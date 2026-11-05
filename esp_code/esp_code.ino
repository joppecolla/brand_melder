#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <WiFiManager.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <Wire.h>
#include <Adafruit_AHTX0.h>
#include <ScioSense_ENS160.h>
#include <DHT20.h>


// --- CONFIGURATIE ---
#define TEMP_ALARM_THRESHOLD 35.0   // °C
#define CO2_HYSTERESE        50     // alarm stopt pas onder (drempel - 50) ppm
#define TEMP_HYSTERESE       1.0    // alarm stopt pas onder (drempel - 1) °C

const int DEFAULT_CO2_ALARM_THRESHOLD = 1000;   // ppm
const unsigned long sensorIntervalMs  = 30000;  // elke 30 s meten
const int           samplesPerUpload  = 4;      // 4 metingen = 2 minuten
const unsigned long snoozeMs          = 10000;  // na knop 1: 10 s stil
const unsigned long alarmPostMinGapMs = 10000;  // min. tijd tussen alarm-posts
const unsigned long debounceDelay     = 50;

// I2C-bus voor DHT20
TwoWire I2C_DHT(1);
DHT20 dht20(&I2C_DHT);

// I2C-bus voor AHT20 en ENS160
Adafruit_AHTX0 aht20;
ScioSense_ENS160 ens160(ENS160_I2CADDR_1);

// Pinconfiguratie
#define ENS_INT_PIN    7
#define LED_BLAUW_PIN  4
#define LED_GROEN_PIN  5
#define BUTTON1_PIN    16   // Knop 1: alarm uit (10 s snooze) / handmatig alarm aan
#define BUTTON2_PIN    15   // Knop 2: online / lokale modus
#define BUZZER_PIN     1

#define DHT_SDA_PIN 8
#define DHT_SCL_PIN 9
#define ENS_SDA_PIN 10
#define ENS_SCL_PIN 11

// Sensorstatus
// CO2-drempel wordt lokaal bewaard in het flashgeheugen
int co2AlarmThreshold = DEFAULT_CO2_ALARM_THRESHOLD;
Preferences preferences;
bool dhtOK = false, ahtOK = false, ensOK = false;
bool temperatureAvailable = false;
bool co2Available = false;
float temperatureC = 0.0f;
int   eco2ppm = 0;
float co2Smooth = 0.0f;   // afgevlakte CO2 voor het alarm (negeert korte uitschieters)
bool  co2Hoog = false;    // CO2 boven drempel (met hysterese)
bool  tempHoog = false;   // temperatuur boven drempel (met hysterese)

// Systeemstatus
bool onlineModus = true;           // wifi aan/uit (knop 2)
bool internetVerbonden = false;
bool melderAan = true;             // detectie aan/uit (dashboard)
const unsigned long pingIntervalMs = 15000;  // status naar server + instellingen ophalen
unsigned long lastPing = 0;

// Alarmstatus
bool manualAlarm = false;          // handmatig alarm via knop 1
bool snoozeActive = false;         // buzzer gedempt na knop 1
unsigned long snoozeStart = 0;
volatile bool buzzerAan = false;   // moet de buzzer nu piepen?
bool vorigeAlarmSituatie = false;  // om verandering te detecteren
bool alarmEventPending = false;    // alarm gestart/gestopt -> direct naar website
unsigned long lastAlarmPost = 0;
bool alarmPostedBefore = false;

// Meetbuffer voor de 2-minuten upload
float sampleTemp[samplesPerUpload];
int   sampleCo2[samplesPerUpload];
int   sampleCount = 0;   // geldige metingen
int   tickCount = 0;     // aantal 30s-intervallen

unsigned long previousSensorMillis = 0;

// HTTPS-client
WiFiClientSecure secureClient;
const char* serverUrl = "https://brand-melder.onrender.com/api.php";

// Interrupt van ENS160 (data klaar) -> loop leest CO2 en controleert de drempel
volatile bool ensInterruptTriggered = false;
void IRAM_ATTR handleEnsInterrupt() {
  ensInterruptTriggered = true;
}

// ENS160 INT-pin inschakelen: INTEN (bit0) + INTDAT (bit1), open-drain, active-low
void ensEnableDataInterrupt() {
  Wire.beginTransmission(ENS160_I2CADDR_1);
  Wire.write(0x11);   // CONFIG register
  Wire.write(0x03);
  Wire.endTransmission();
}

// Buzzer in eigen taak: blijft netjes pieptonen, ook als de ESP even met de server bezig is
void buzzerTask(void* param) {
  for (;;) {
    if (buzzerAan) {
      digitalWrite(BUZZER_PIN, HIGH);
      vTaskDelay(200 / portTICK_PERIOD_MS);
      digitalWrite(BUZZER_PIN, LOW);
      vTaskDelay(200 / portTICK_PERIOD_MS);
    } else {
      digitalWrite(BUZZER_PIN, LOW);
      vTaskDelay(20 / portTICK_PERIOD_MS);
    }
  }
}

// Nieuwe CO2-waarde verwerken: afvlakken + drempel met hysterese (voorkomt aan/uit-geflipper rond 600 ppm)
void updateCo2(int c) {
  if (c <= 0) return;
  eco2ppm = c;
  co2Available = true;
  co2Smooth = (co2Smooth == 0.0f) ? (float)c : (0.8f * co2Smooth + 0.2f * c);
  if (!co2Hoog && co2Smooth > co2AlarmThreshold) co2Hoog = true;
  else if (co2Hoog && co2Smooth < co2AlarmThreshold - CO2_HYSTERESE) co2Hoog = false;
}

void updateTemp() {
  if (!temperatureAvailable) return;
  if (!tempHoog && temperatureC > TEMP_ALARM_THRESHOLD) tempHoog = true;
  else if (tempHoog && temperatureC < TEMP_ALARM_THRESHOLD - TEMP_HYSTERESE) tempHoog = false;
}

// Snelle CO2-update voor het alarm (telt NIET als meting)
void readCo2ForAlarm() {
  if (!ensOK) return;
  ens160.measure(true);
  updateCo2(ens160.geteCO2());
}

// Alle sensoren uitlezen (elke 30 s)
void readSensors() {
  float dhtTemperature = NAN, ahtTemperature = NAN;

  if (dhtOK && dht20.read() == 0) dhtTemperature = dht20.getTemperature();

  if (ahtOK) {
    sensors_event_t humidity, temp;
    aht20.getEvent(&humidity, &temp);
    ahtTemperature = temp.temperature;
  }

  bool dhtValid = !isnan(dhtTemperature);
  bool ahtValid = !isnan(ahtTemperature);

  if (dhtValid && ahtValid) { temperatureC = (dhtTemperature + ahtTemperature) / 2.0f; temperatureAvailable = true; }
  else if (dhtValid)        { temperatureC = dhtTemperature; temperatureAvailable = true; }
  else if (ahtValid)        { temperatureC = ahtTemperature; temperatureAvailable = true; }
  else                      { temperatureAvailable = false; }

  updateTemp();

  if (ensOK) {
    ens160.measure(true);
    int c = ens160.geteCO2();
    if (c > 0) updateCo2(c);
    else       co2Available = false;
  }

  Serial.print("[METING ");
  Serial.print(tickCount + 1);
  Serial.print("/");
  Serial.print(samplesPerUpload);
  Serial.print("] Temp: ");
  if (temperatureAvailable) Serial.print(temperatureC, 1); else Serial.print("Fout");
  Serial.print(" °C | CO2: ");
  if (co2Available) Serial.print(eco2ppm); else Serial.print("Fout");
  Serial.println(" ppm");
}

// Reden van het alarm (voor de website)
const char* alarmReden() {
  if (manualAlarm) return "handmatig";
  if (co2Hoog) return "co2";
  if (tempHoog) return "temperatuur";
  return "";
}

// Melder aan/uit zetten (opdracht van het dashboard)
void setMelderAan(bool aan) {
  if (aan == melderAan) return;
  melderAan = aan;
  Serial.println(aan ? ">>> [SERVER] Melder ingeschakeld via dashboard." : ">>> [SERVER] Melder uitgeschakeld via dashboard.");
  if (!aan) {
    manualAlarm = false;
    snoozeActive = false;
  }
}

// Velden die in elk bericht aan de server zitten
String statusFields(const char* status, bool alarm, bool gedempt) {
  String s = ",\"batterij\":100";
  s += ",\"alarm\":" + String(alarm ? "true" : "false");
  s += ",\"gedempt\":" + String(gedempt ? "true" : "false");
  s += ",\"reden\":\"" + String(alarm ? alarmReden() : "") + "\"";
  s += ",\"melder_aan\":" + String(melderAan ? "true" : "false");
  s += ",\"status\":\"" + String(status) + "\"";
  return s;
}

// JSON posten en antwoord verwerken (server stuurt mee of de melder aan moet staan)
bool postJson(const String& body) {
  if (WiFi.status() != WL_CONNECTED) {
    internetVerbonden = false;
    return false;
  }

  HTTPClient http;
  http.setConnectTimeout(3000);
  http.setTimeout(3000);

  if (!http.begin(secureClient, serverUrl)) {
    Serial.println(">>> [SERVER] Fout: HTTPClient kon niet worden gestart.");
    internetVerbonden = false;
    return false;
  }

  http.addHeader("Content-Type", "application/json");
  int code = http.POST(body);
  bool ok = false;

  if (code == 200) {
    String response = http.getString();
    internetVerbonden = true;
    ok = true;
    lastPing = millis();
    Serial.println(">>> [SERVER] Antwoord: " + response);

    if (response.indexOf("\"melder\":false") != -1)      setMelderAan(false);
    else if (response.indexOf("\"melder\":true") != -1)  setMelderAan(true);
    else Serial.println(">>> [SERVER] LET OP: antwoord bevat geen 'melder'. Staat de NIEUWE api.php online?");
        // Nieuwe CO2-drempel ophalen uit de server
    const String sleutel = "\"co2_drempel\":";
    int positie = response.indexOf(sleutel);

    if (positie >= 0) {
      int begin = positie + sleutel.length();
      int einde = response.indexOf(',', begin);

      if (einde < 0) {
        einde = response.indexOf('}', begin);
      }

      int nieuweDrempel = response.substring(begin, einde).toInt();

      if (nieuweDrempel >= 1 && nieuweDrempel <= 5000) {
        if (nieuweDrempel != co2AlarmThreshold) {

          co2AlarmThreshold = nieuweDrempel;

          preferences.begin("brandmelder", false);
          preferences.putInt("co2threshold", co2AlarmThreshold);
          preferences.end();

          Serial.printf(
              "Nieuwe CO2-drempel opgeslagen: %d ppm\n",
              co2AlarmThreshold
          );
        }
      }
    }
  } else {
    Serial.println(">>> [SERVER] Fout: " + (code > 0 ? String(code) : http.errorToString(code)));
    internetVerbonden = false;
  }
  http.end();
  return ok;
}

// Alleen status doorgeven (geen meetwaarden)
bool sendPing(const char* status) {
  String body = "{\"ping\":true" + statusFields(status, vorigeAlarmSituatie, vorigeAlarmSituatie && snoozeActive) + "}";
  return postJson(body);
}

// Meetwaarden versturen (gemiddelde of directe alarmmelding)
void sendMeasurements(float temp, int co2, bool alarm, bool gedempt, int aantalMetingen) {
  if (!onlineModus) return;
  if (!(temp > -20 && temp < 80 && co2 > 300)) return;

  Serial.println("\n>>> [SERVER] Bezig met versturen...");

  String body = "{\"temperatuur\":" + String(temp, 2) + ",\"co2\":" + String(co2);
  body += statusFields("online", alarm, gedempt);
  body += ",\"metingen\":" + String(aantalMetingen) + "}";

  if (postJson(body)) Serial.println(">>> [SERVER] Verzonden!");
  Serial.println("--------------------------------------------------");
}

// Gemiddelde van de verzamelde metingen versturen en buffer leegmaken
void uploadAverage() {
  if (sampleCount > 0) {
    float tSum = 0; long cSum = 0;
    for (int i = 0; i < sampleCount; i++) { tSum += sampleTemp[i]; cSum += sampleCo2[i]; }
    float tAvg = tSum / sampleCount;
    int   cAvg = cSum / sampleCount;
    bool situatie = vorigeAlarmSituatie;
    sendMeasurements(tAvg, cAvg, situatie, snoozeActive, sampleCount);
  }
  sampleCount = 0;
  tickCount = 0;
}

void setup() {
  Serial.begin(115200);
  delay(500);
    // Laatst opgeslagen CO2-drempel ophalen
  preferences.begin("brandmelder", true);

  int opgeslagenDrempel = preferences.getInt(
      "co2threshold",
      DEFAULT_CO2_ALARM_THRESHOLD
  );

  preferences.end();

  if (opgeslagenDrempel >= 1 && opgeslagenDrempel <= 5000) {
    co2AlarmThreshold = opgeslagenDrempel;
  }

  Serial.printf("CO2-alarmdrempel: %d ppm\n", co2AlarmThreshold);

  pinMode(LED_BLAUW_PIN, OUTPUT);
  pinMode(LED_GROEN_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(ENS_INT_PIN, INPUT_PULLUP);
  pinMode(BUTTON1_PIN, INPUT_PULLUP);
  pinMode(BUTTON2_PIN, INPUT_PULLUP);

  digitalWrite(LED_BLAUW_PIN, HIGH);
  digitalWrite(LED_GROEN_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);
  xTaskCreate(buzzerTask, "buzzer", 2048, NULL, 1, NULL);

  I2C_DHT.begin(DHT_SDA_PIN, DHT_SCL_PIN, 100000);
  Wire.begin(ENS_SDA_PIN, ENS_SCL_PIN, 100000);

  Serial.println("\n=== BRANDMELDER START ===");

  dhtOK = dht20.begin();
  ahtOK = aht20.begin(&Wire);
  ensOK = ens160.begin();

  if (ensOK) {
    ensEnableDataInterrupt();              // INT-pin van ENS160 aanzetten
    ens160.setMode(ENS160_OPMODE_STD);
  }

  // Interrupt altijd actief
  attachInterrupt(digitalPinToInterrupt(ENS_INT_PIN), handleEnsInterrupt, FALLING);

  WiFiManager wm;
  wm.setConnectTimeout(20);          // max 20 s proberen met opgeslagen wifi
  wm.setConfigPortalTimeout(120);    // setup-portaal max 2 min open, daarna lokaal verder
  if (!wm.autoConnect("ESP32_Brandmelder", "12345678")) {
    Serial.println("Geen wifi gelukt. Draait lokaal door (knop 2 = opnieuw proberen).");
    onlineModus = false;
  } else {
    Serial.println("Verbonden met wifi!");
    internetVerbonden = true;
  }

  secureClient.setInsecure();
  previousSensorMillis = millis();
  if (onlineModus && internetVerbonden) sendPing("online");
}

void loop() {
  unsigned long now = millis();

  // --- KNOP 1 (alleen op indrukken, niet bij ingedrukt houden) ---
  static bool b1Prev = HIGH;
  static unsigned long lastB1 = 0;
  bool b1 = digitalRead(BUTTON1_PIN);
  if (melderAan && b1 == LOW && b1Prev == HIGH && now - lastB1 > debounceDelay) {
    lastB1 = now;
    if (buzzerAan) {
      // Alarm gaat uit, ook als de waarde nog te hoog is
      manualAlarm = false;
      snoozeActive = true;
      snoozeStart = now;
      Serial.println(">>> [KNOP] Alarm uit. Mag pas na 10 s weer afgaan.");
    } else {
      manualAlarm = true;
      Serial.println(">>> [KNOP] Handmatig alarm geactiveerd!");
    }
  }
  b1Prev = b1;

  // --- KNOP 2: online / lokale modus ---
  static bool b2Prev = HIGH;
  static unsigned long lastB2 = 0;
  bool b2 = digitalRead(BUTTON2_PIN);
  if (b2 == LOW && b2Prev == HIGH && now - lastB2 > debounceDelay) {
    lastB2 = now;
    onlineModus = !onlineModus;
    if (!onlineModus) {
      Serial.println(">>> [KNOP] LOKALE MODUS (WiFi uit).");
      if (WiFi.status() == WL_CONNECTED) sendPing("lokaal");   // dashboard laten weten
      WiFi.disconnect(true);
      WiFi.mode(WIFI_OFF);
      internetVerbonden = false;
    } else {
      Serial.println(">>> [KNOP] Online modus. Verbinden met WiFi...");
      WiFi.mode(WIFI_STA);
      WiFi.begin();
      unsigned long startConnect = millis();
      while (WiFi.status() != WL_CONNECTED && millis() - startConnect < 5000) delay(100);
      internetVerbonden = (WiFi.status() == WL_CONNECTED);
      if (internetVerbonden) sendPing("online");
    }
  }
  b2Prev = b2;

  // --- ENS160 INTERRUPT: nieuwe data -> CO2 bijwerken voor het alarm ---
  if (ensInterruptTriggered) {
    ensInterruptTriggered = false;
    readCo2ForAlarm();
  }

  // --- SNOOZE VERLOPEN ---
  if (snoozeActive && now - snoozeStart >= snoozeMs) {
    snoozeActive = false;
    Serial.println(">>> [ALARM] Snooze voorbij.");
  }

  // --- 30-SECONDEN METING ---
  if (now - previousSensorMillis >= sensorIntervalMs) {
    previousSensorMillis = now;
    readSensors();
    if (temperatureAvailable && co2Available) {
      sampleTemp[sampleCount] = temperatureC;
      sampleCo2[sampleCount] = eco2ppm;
      sampleCount++;
    }
    tickCount++;
  }

  // --- ALARMSITUATIE BEPALEN ---
  bool overDrempel = co2Hoog || tempHoog;
  bool alarmSituatie = melderAan && (overDrempel || manualAlarm);   // dit gaat naar de website
  buzzerAan = melderAan && ((overDrempel && !snoozeActive) || manualAlarm);

  if (alarmSituatie != vorigeAlarmSituatie) {
    vorigeAlarmSituatie = alarmSituatie;
    alarmEventPending = true;
    Serial.println(alarmSituatie ? ">>> [ALARM] Gestart!" : ">>> [ALARM] Voorbij.");
  }


  // --- ALARM DIRECT NAAR WEBSITE ---
  if (alarmEventPending && (!alarmPostedBefore || now - lastAlarmPost >= alarmPostMinGapMs)) {
    alarmEventPending = false;
    if (onlineModus && WiFi.status() == WL_CONNECTED) {
      sendMeasurements(temperatureC, eco2ppm, alarmSituatie, snoozeActive, 1);
      lastAlarmPost = millis();
      alarmPostedBefore = true;
    }
  }

  // --- ELKE 4 METINGEN (2 MINUTEN) UPLOADEN ---
  if (tickCount >= samplesPerUpload) {
    if (onlineModus) uploadAverage();
    else { sampleCount = 0; tickCount = 0; }
  }

  // --- WIFI HERSTEL: opnieuw verbinden als de verbinding weg is ---
  static unsigned long lastReconnect = 0;
  if (onlineModus && WiFi.status() != WL_CONNECTED && now - lastReconnect >= 10000) {
    lastReconnect = now;
    Serial.println(">>> [WIFI] Niet verbonden, opnieuw proberen...");
    WiFi.mode(WIFI_STA);
    WiFi.begin();   // gebruikt de opgeslagen wifi-gegevens
  }

  // --- DEBUG: elke 10 s de toestand op de seriële monitor ---
  static unsigned long lastDebug = 0;
  if (now - lastDebug >= 10000) {
    lastDebug = now;
    Serial.printf("[STATUS] onlineModus=%d wifi=%d melderAan=%d alarm=%d\n",
                  onlineModus, (int)WiFi.status(), melderAan, vorigeAlarmSituatie);
  }

  // --- STATUS NAAR SERVER + INSTELLINGEN OPHALEN ---
  if (onlineModus && WiFi.status() == WL_CONNECTED && now - lastPing >= pingIntervalMs) {
    lastPing = now;   // ook bij een fout, anders blijft hij het elke ronde proberen
    sendPing("online");
  }

  // --- BLAUWE LED: melder staat aan (ook in lokale modus), uit als via dashboard uitgezet ---
  digitalWrite(LED_BLAUW_PIN, melderAan ? HIGH : LOW);

  // --- GROENE LED: wifi-verbinding ---
  if (onlineModus) {
    internetVerbonden = (WiFi.status() == WL_CONNECTED);
    digitalWrite(LED_GROEN_PIN, internetVerbonden ? HIGH : LOW);
  } else {
    digitalWrite(LED_GROEN_PIN, LOW);
  }

  delay(2);
}

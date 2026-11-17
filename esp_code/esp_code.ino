#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <WiFiManager.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <Wire.h>
#include <Adafruit_AHTX0.h>
#include <ScioSense_ENS160.h>
#include <DHT20.h>
#include <DFRobotDFPlayerMini.h>

// --- CONFIGURATIE ---
#define TEMP_ALARM_THRESHOLD 35.0   // °C
#define CO2_HYSTERESE        50     // alarm stopt pas onder (drempel - 50) ppm
#define TEMP_HYSTERESE       1.0    // alarm stopt pas onder (drempel - 1) °C
#define melder_id            2

const int           DEFAULT_CO2_ALARM_THRESHOLD = 1000;               // ppm
const unsigned long DEFAULT_VERZEND_INTERVAL_MS = 120000;             // standaard elke 2 minuten
const int           MAX_SAMPLES                 = 120;                // 3600 s / 30 s
unsigned long       verzendIntervalMs = DEFAULT_VERZEND_INTERVAL_MS;
unsigned long       sensorIntervalMs  = 30000;                        // wordt berekend uit het verzendinterval
int                 samplesPerUpload  = 4;                            // wordt berekend uit het verzendinterval
const unsigned long snoozeMs          = 10000;                        // na knop 1: 10 s stil
const unsigned long alarmPostMinGapMs = 10000;                        // min. tijd tussen alarm-posts
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

#define DHT_SDA_PIN 8
#define DHT_SCL_PIN 9
#define ENS_SDA_PIN 10
#define ENS_SCL_PIN 11

// --- DFPLAYER CONFIGURATIE ---
HardwareSerial mp3Serial(1);
DFRobotDFPlayerMini dfPlayer;
const int totaalAantalLiedjes = 14; 

unsigned long lastSongStart = 0;
const unsigned long songIntervalMs = 60000; // Elke 60 seconden een nieuw willekeurig liedje bij een actief alarm

// Sensorstatus
int co2AlarmThreshold = DEFAULT_CO2_ALARM_THRESHOLD;
Preferences preferences;
bool dhtOK = false, ahtOK = false, ensOK = false;
bool temperatureAvailable = false;
bool co2Available = false;
float temperatureC = 0.0f;
int   eco2ppm = 0;
float co2Smooth = 0.0f;   
bool  co2Hoog = false;    
bool  tempHoog = false;   

// Systeemstatus
bool onlineModus = true;           
bool internetVerbonden = false;
bool melderAan = true;             
const unsigned long pingIntervalMs = 15000;  
unsigned long lastPing = 0;

// Alarmstatus
bool manualAlarm = false;          
bool snoozeActive = false;         
unsigned long snoozeStart = 0;
bool alarmAan = false;             
bool vorigeAlarmSituatie = false;  
bool alarmEventPending = false;    
unsigned long lastAlarmPost = 0;
bool alarmPostedBefore = false;

// Muziek & Volume
bool remoteMusic = false;
bool musicStarted = false;
int gekozenLiedje = 0;
int vorigGekozenLiedje = -1; 
int huidigVolume = 25;
int vorigVolume = -1;

// Meetbuffer voor de 2-minuten upload
float sampleTemp[MAX_SAMPLES];
int   sampleCo2[MAX_SAMPLES];
int   sampleCount = 0;   
int   tickCount = 0;     

unsigned long previousSensorMillis = 0;

// Rekent uit hoe vaak er gemeten wordt (max. 30 s tussen metingen)
void stelVerzendIntervalIn(unsigned long ms) {
  verzendIntervalMs = ms;
  samplesPerUpload  = (ms + 29999) / 30000;
  if (samplesPerUpload > MAX_SAMPLES) samplesPerUpload = MAX_SAMPLES;
  sensorIntervalMs  = ms / samplesPerUpload;
  Serial.print(">>> [INTERVAL] Versturen elke ");
  Serial.print(ms / 1000);
  Serial.print(" s, meten elke ");
  Serial.print(sensorIntervalMs / 1000);
  Serial.println(" s");
}

// HTTPS-client
WiFiClientSecure secureClient;
const char* serverUrl = "https://brand-melder-vfva.onrender.com/api.php";

// Root-certificaten om te controleren dat we echt met onze server praten
// (GTS Root R4 = Render nu, GTS Root R1 en ISRG Root X1 = reserve)
const char* rootCertificaten = R"CERT(
-----BEGIN CERTIFICATE-----
MIICCTCCAY6gAwIBAgINAgPlwGjvYxqccpBQUjAKBggqhkjOPQQDAzBHMQswCQYD
VQQGEwJVUzEiMCAGA1UEChMZR29vZ2xlIFRydXN0IFNlcnZpY2VzIExMQzEUMBIG
A1UEAxMLR1RTIFJvb3QgUjQwHhcNMTYwNjIyMDAwMDAwWhcNMzYwNjIyMDAwMDAw
WjBHMQswCQYDVQQGEwJVUzEiMCAGA1UEChMZR29vZ2xlIFRydXN0IFNlcnZpY2Vz
IExMQzEUMBIGA1UEAxMLR1RTIFJvb3QgUjQwdjAQBgcqhkjOPQIBBgUrgQQAIgNi
AATzdHOnaItgrkO4NcWBMHtLSZ37wWHO5t5GvWvVYRg1rkDdc/eJkTBa6zzuhXyi
QHY7qca4R9gq55KRanPpsXI5nymfopjTX15YhmUPoYRlBtHci8nHc8iMai/lxKvR
HYqjQjBAMA4GA1UdDwEB/wQEAwIBhjAPBgNVHRMBAf8EBTADAQH/MB0GA1UdDgQW
BBSATNbrdP9JNqPV2Py1PsVq8JQdjDAKBggqhkjOPQQDAwNpADBmAjEA6ED/g94D
9J+uHXqnLrmvT/aDHQ4thQEd0dlq7A/Cr8deVl5c1RxYIigL9zC2L7F8AjEA8GE8
p/SgguMh1YQdc4acLa/KNJvxn7kjNuK8YAOdgLOaVsjh4rsUecrNIdSUtUlD
-----END CERTIFICATE-----
-----BEGIN CERTIFICATE-----
MIIFVzCCAz+gAwIBAgINAgPlk28xsBNJiGuiFzANBgkqhkiG9w0BAQwFADBHMQsw
CQYDVQQGEwJVUzEiMCAGA1UEChMZR29vZ2xlIFRydXN0IFNlcnZpY2VzIExMQzEU
MBIGA1UEAxMLR1RTIFJvb3QgUjEwHhcNMTYwNjIyMDAwMDAwWhcNMzYwNjIyMDAw
MDAwWjBHMQswCQYDVQQGEwJVUzEiMCAGA1UEChMZR29vZ2xlIFRydXN0IFNlcnZp
Y2VzIExMQzEUMBIGA1UEAxMLR1RTIFJvb3QgUjEwggIiMA0GCSqGSIb3DQEBAQUA
A4ICDwAwggIKAoICAQC2EQKLHuOhd5s73L+UPreVp0A8of2C+X0yBoJx9vaMf/vo
27xqLpeXo4xL+Sv2sfnOhB2x+cWX3u+58qPpvBKJXqeqUqv4IyfLpLGcY9vXmX7w
Cl7raKb0xlpHDU0QM+NOsROjyBhsS+z8CZDfnWQpJSMHobTSPS5g4M/SCYe7zUjw
TcLCeoiKu7rPWRnWr4+wB7CeMfGCwcDfLqZtbBkOtdh+JhpFAz2weaSUKK0Pfybl
qAj+lug8aJRT7oM6iCsVlgmy4HqMLnXWnOunVmSPlk9orj2XwoSPwLxAwAtcvfaH
szVsrBhQf4TgTM2S0yDpM7xSma8ytSmzJSq0SPly4cpk9+aCEI3oncKKiPo4Zor8
Y/kB+Xj9e1x3+naH+uzfsQ55lVe0vSbv1gHR6xYKu44LtcXFilWr06zqkUspzBmk
MiVOKvFlRNACzqrOSbTqn3yDsEB750Orp2yjj32JgfpMpf/VjsPOS+C12LOORc92
wO1AK/1TD7Cn1TsNsYqiA94xrcx36m97PtbfkSIS5r762DL8EGMUUXLeXdYWk70p
aDPvOmbsB4om3xPXV2V4J95eSRQAogB/mqghtqmxlbCluQ0WEdrHbEg8QOB+DVrN
VjzRlwW5y0vtOUucxD/SVRNuJLDWcfr0wbrM7Rv1/oFB2ACYPTrIrnqYNxgFlQID
AQABo0IwQDAOBgNVHQ8BAf8EBAMCAYYwDwYDVR0TAQH/BAUwAwEB/zAdBgNVHQ4E
FgQU5K8rJnEaK0gnhS9SZizv8IkTcT4wDQYJKoZIhvcNAQEMBQADggIBAJ+qQibb
C5u+/x6Wki4+omVKapi6Ist9wTrYggoGxval3sBOh2Z5ofmmWJyq+bXmYOfg6LEe
QkEzCzc9zolwFcq1JKjPa7XSQCGYzyI0zzvFIoTgxQ6KfF2I5DUkzps+GlQebtuy
h6f88/qBVRRiClmpIgUxPoLW7ttXNLwzldMXG+gnoot7TiYaelpkttGsN/H9oPM4
7HLwEXWdyzRSjeZ2axfG34arJ45JK3VmgRAhpuo+9K4l/3wV3s6MJT/KYnAK9y8J
ZgfIPxz88NtFMN9iiMG1D53Dn0reWVlHxYciNuaCp+0KueIHoI17eko8cdLiA6Ef
MgfdG+RCzgwARWGAtQsgWSl4vflVy2PFPEz0tv/bal8xa5meLMFrUKTX5hgUvYU/
Z6tGn6D/Qqc6f1zLXbBwHSs09dR2CQzreExZBfMzQsNhFRAbd03OIozUhfJFfbdT
6u9AWpQKXCBfTkBdYiJ23//OYb2MI3jSNwLgjt7RETeJ9r/tSQdirpLsQBqvFAnZ
0E6yove+7u7Y/9waLd64NnHi/Hm3lCXRSHNboTXns5lndcEZOitHTtNCjv0xyBZm
2tIMPNuzjsmhDYAPexZ3FL//2wmUspO8IFgV6dtxQ/PeEMMA3KgqlbbC1j+Qa3bb
bP6MvPJwNQzcmRk13NfIRmPVNnGuV/u3gm3c
-----END CERTIFICATE-----
-----BEGIN CERTIFICATE-----
MIIFazCCA1OgAwIBAgIRAIIQz7DSQONZRGPgu2OCiwAwDQYJKoZIhvcNAQELBQAw
TzELMAkGA1UEBhMCVVMxKTAnBgNVBAoTIEludGVybmV0IFNlY3VyaXR5IFJlc2Vh
cmNoIEdyb3VwMRUwEwYDVQQDEwxJU1JHIFJvb3QgWDEwHhcNMTUwNjA0MTEwNDM4
WhcNMzUwNjA0MTEwNDM4WjBPMQswCQYDVQQGEwJVUzEpMCcGA1UEChMgSW50ZXJu
ZXQgU2VjdXJpdHkgUmVzZWFyY2ggR3JvdXAxFTATBgNVBAMTDElTUkcgUm9vdCBY
MTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAK3oJHP0FDfzm54rVygc
h77ct984kIxuPOZXoHj3dcKi/vVqbvYATyjb3miGbESTtrFj/RQSa78f0uoxmyF+
0TM8ukj13Xnfs7j/EvEhmkvBioZxaUpmZmyPfjxwv60pIgbz5MDmgK7iS4+3mX6U
A5/TR5d8mUgjU+g4rk8Kb4Mu0UlXjIB0ttov0DiNewNwIRt18jA8+o+u3dpjq+sW
T8KOEUt+zwvo/7V3LvSye0rgTBIlDHCNAymg4VMk7BPZ7hm/ELNKjD+Jo2FR3qyH
B5T0Y3HsLuJvW5iB4YlcNHlsdu87kGJ55tukmi8mxdAQ4Q7e2RCOFvu396j3x+UC
B5iPNgiV5+I3lg02dZ77DnKxHZu8A/lJBdiB3QW0KtZB6awBdpUKD9jf1b0SHzUv
KBds0pjBqAlkd25HN7rOrFleaJ1/ctaJxQZBKT5ZPt0m9STJEadao0xAH0ahmbWn
OlFuhjuefXKnEgV4We0+UXgVCwOPjdAvBbI+e0ocS3MFEvzG6uBQE3xDk3SzynTn
jh8BCNAw1FtxNrQHusEwMFxIt4I7mKZ9YIqioymCzLq9gwQbooMDQaHWBfEbwrbw
qHyGO0aoSCqI3Haadr8faqU9GY/rOPNk3sgrDQoo//fb4hVC1CLQJ13hef4Y53CI
rU7m2Ys6xt0nUW7/vGT1M0NPAgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNV
HRMBAf8EBTADAQH/MB0GA1UdDgQWBBR5tFnme7bl5AFzgAiIyBpY9umbbjANBgkq
hkiG9w0BAQsFAAOCAgEAVR9YqbyyqFDQDLHYGmkgJykIrGF1XIpu+ILlaS/V9lZL
ubhzEFnTIZd+50xx+7LSYK05qAvqFyFWhfFQDlnrzuBZ6brJFe+GnY+EgPbk6ZGQ
3BebYhtF8GaV0nxvwuo77x/Py9auJ/GpsMiu/X1+mvoiBOv/2X/qkSsisRcOj/KK
NFtY2PwByVS5uCbMiogziUwthDyC3+6WVwW6LLv3xLfHTjuCvjHIInNzktHCgKQ5
ORAzI4JMPJ+GslWYHb4phowim57iaztXOoJwTdwJx4nLCgdNbOhdjsnvzqvHu7Ur
TkXWStAmzOVyyghqpZXjFaH3pO3JLF+l+/+sKAIuvtd7u+Nxe5AW0wdeRlN8NwdC
jNPElpzVmbUq4JUagEiuTDkHzsxHpFKVK7q4+63SM1N95R1NbdWhscdCb+ZAJzVc
oyi3B43njTOQ5yOf+1CceWxG1bQVs5ZufpsMljq4Ui0/1lvh+wjChP4kqKOJ2qxq
4RgqsahDYVvTH9w7jXbyLeiNdd8XM2w9U/t7y0Ff/9yi0GE44Za4rF2LN9d11TPA
mRGunUHBcnWEvgJBQl9nJEiU0Zsnvgc/ubhPgXRR4Xq37Z0j4r7g1SgEEzwxA57d
emyPxgcYxn/eR44/KJ4EBs+lVDR3veyJm+kXQ99b21/+jh5Xos1AnX5iItreGCc=
-----END CERTIFICATE-----
)CERT";

// Interrupt van ENS160
volatile bool ensInterruptTriggered = false;
void IRAM_ATTR handleEnsInterrupt() {
  ensInterruptTriggered = true;
}

void ensEnableDataInterrupt() {
  Wire.beginTransmission(ENS160_I2CADDR_1);
  Wire.write(0x11);   
  Wire.write(0x03);
  Wire.endTransmission();
}

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

void readCo2ForAlarm() {
  if (!ensOK) return;
  ens160.measure(true);
  updateCo2(ens160.geteCO2());
}

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
}

const char* alarmReden() {
  if (manualAlarm) return "handmatig";
  if (co2Hoog) return "co2";
  if (tempHoog) return "temperatuur";
  return "";
}

void setMelderAan(bool aan) {
  if (aan == melderAan) return;
  melderAan = aan;
  Serial.println(aan ? ">>> [SERVER] Melder ingeschakeld via dashboard." : ">>> [SERVER] Melder uitgeschakeld via dashboard.");
  if (!aan) {
    manualAlarm = false;
    snoozeActive = false;
    dfPlayer.pause(); 
  }
}

String statusFields(const char* status, bool alarm, bool gedempt) {
  String s = ",\"melder_id\":" + String(melder_id);
  s += ",\"batterij\":100";
  s += ",\"alarm\":" + String(alarm ? "true" : "false");
  s += ",\"gedempt\":" + String(gedempt ? "true" : "false");
  s += ",\"reden\":\"" + String(alarm ? alarmReden() : "") + "\"";
  s += ",\"melder_aan\":" + String(melderAan ? "true" : "false");
  s += ",\"status\":\"" + String(status) + "\"";
  return s;
}

bool postJson(const String& body) {
  if (WiFi.status() != WL_CONNECTED) {
    internetVerbonden = false;
    return false;
  }

  HTTPClient http;
  http.setConnectTimeout(3000);
  http.setTimeout(3000);

  if (!http.begin(secureClient, serverUrl)) {
    internetVerbonden = false;
    return false;
  }

  http.addHeader("Content-Type", "application/json");
  int code = http.POST(body);
  bool ok = false;

  if (code == 200) {
    String response = http.getString();
    Serial.println("===== SERVER =====");
    Serial.println(response);
    Serial.println("==================");
    internetVerbonden = true;
    ok = true;
    lastPing = millis();

    if (response.indexOf("\"melder\":false") != -1)      setMelderAan(false);
    else if (response.indexOf("\"melder\":true") != -1)  setMelderAan(true);
    
    if (response.indexOf("\"muziek\":true") != -1) {
      if (!remoteMusic) {
        remoteMusic = true;
        musicStarted = false;
      }
    }
    else if (response.indexOf("\"muziek\":false") != -1) {
      remoteMusic = false;
      musicStarted = false;

      if (!alarmAan) {
        dfPlayer.pause();
      }
    }

    // Gekozen liedje uitlezen
    const String liedjeKey = "\"gekozen_liedje\":";
    int liedjePos = response.indexOf(liedjeKey);

    if (liedjePos >= 0) {
      int begin = liedjePos + liedjeKey.length();
      int einde = response.indexOf(',', begin);
      if (einde < 0) einde = response.indexOf('}', begin);
      gekozenLiedje = response.substring(begin, einde).toInt();
    }

    // Volume uitlezen
    const String volKey = "\"volume\":";
    int volPos = response.indexOf(volKey);

    if (volPos >= 0) {
      int begin = volPos + volKey.length();
      int einde = response.indexOf(',', begin);
      if (einde < 0) einde = response.indexOf('}', begin);
      huidigVolume = response.substring(begin, einde).toInt();

      if (huidigVolume != vorigVolume) {
        if (huidigVolume >= 0 && huidigVolume <= 30) {
          dfPlayer.volume(huidigVolume);
          vorigVolume = huidigVolume;
          Serial.print(">>> [MP3] Volume aangepast via dashboard: ");
          Serial.println(huidigVolume);
        }
      }
    }

    // CO2-drempel uitlezen
    const String sleutel = "\"co2_drempel\":";
    int positie = response.indexOf(sleutel);

    if (positie >= 0) {
      int begin = positie + sleutel.length();
      int einde = response.indexOf(',', begin);
      if (einde < 0) einde = response.indexOf('}', begin);
      int nieuweDrempel = response.substring(begin, einde).toInt();

      if (nieuweDrempel >= 1 && nieuweDrempel <= 5000) {
        if (nieuweDrempel != co2AlarmThreshold) {
          co2AlarmThreshold = nieuweDrempel;
          preferences.begin("brandmelder", false);
          preferences.putInt("co2threshold", co2AlarmThreshold);
          preferences.end();
        }
      }
    }
    
    // Verzendinterval uitlezen (in seconden, ingesteld via admin)
    const String intervalSleutel = "\"verzend_interval\":";
    int intervalPositie = response.indexOf(intervalSleutel);

    if (intervalPositie >= 0) {
      int begin = intervalPositie + intervalSleutel.length();
      int einde = response.indexOf(',', begin);
      if (einde < 0) einde = response.indexOf('}', begin);
      long seconden = response.substring(begin, einde).toInt();

      if (seconden >= 10 && seconden <= 3600) {
        unsigned long nieuwMs = (unsigned long)seconden * 1000UL;
        if (nieuwMs != verzendIntervalMs) {
          stelVerzendIntervalIn(nieuwMs);
          preferences.begin("brandmelder", false);
          preferences.putULong("verzendint", nieuwMs);
          preferences.end();
        }
      }
    }
  } else {
    internetVerbonden = false;
  }
  http.end();
  return ok;
}

bool sendPing(const char* status) {
  String body = "{\"ping\":true" + statusFields(status, vorigeAlarmSituatie, vorigeAlarmSituatie && snoozeActive) + "}";
  return postJson(body);
}

void sendMeasurements(float temp, int co2, bool alarm, bool gedempt, int aantalMetingen) {
  if (!onlineModus) return;
  if (!(temp > -20 && temp < 80 && co2 > 300)) return;

  Serial.println("\n>>> [SERVER] Bezig met versturen van meting...");
  String body = "{\"temperatuur\":" + String(temp, 2) + ",\"co2\":" + String(co2);
  body += statusFields("online", alarm, gedempt);
  body += ",\"metingen\":" + String(aantalMetingen) + "}";

  if (postJson(body)) {
    Serial.println(">>> [SERVER] Meting succesvol verzonden!");
  }
  Serial.println("--------------------------------------------------");
}

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

void playRandomSong() {
  int willekeurigNummer = random(1, totaalAantalLiedjes + 1);
  dfPlayer.play(willekeurigNummer);
  Serial.print(">>> [MP3] Willekeurig liedje gestart: ");
  Serial.println(willekeurigNummer);
}

void setup() {
  Serial.begin(115200);
  delay(500);

  mp3Serial.begin(9600, SERIAL_8N1, 18, 17); 
  if (!dfPlayer.begin(mp3Serial)) {
    Serial.println("DFPlayer Mini kon niet worden gestart! Controleer de bedrading.");
  } else {
    Serial.println("DFPlayer Mini online!");
    dfPlayer.volume(huidigVolume); 
  }

  preferences.begin("brandmelder", true);
  int opgeslagenDrempel = preferences.getInt("co2threshold", DEFAULT_CO2_ALARM_THRESHOLD);
  unsigned long opgeslagenInterval = preferences.getULong("verzendint", DEFAULT_VERZEND_INTERVAL_MS);
  preferences.end();

  if (opgeslagenDrempel >= 1 && opgeslagenDrempel <= 5000) {
    co2AlarmThreshold = opgeslagenDrempel;
  }

  if (opgeslagenInterval >= 10000 && opgeslagenInterval <= 3600000) {
    stelVerzendIntervalIn(opgeslagenInterval);
  } else {
    stelVerzendIntervalIn(DEFAULT_VERZEND_INTERVAL_MS);
  }

  pinMode(LED_BLAUW_PIN, OUTPUT);
  pinMode(LED_GROEN_PIN, OUTPUT);
  pinMode(ENS_INT_PIN, INPUT_PULLUP);
  pinMode(BUTTON1_PIN, INPUT_PULLUP);
  pinMode(BUTTON2_PIN, INPUT_PULLUP);

  digitalWrite(LED_BLAUW_PIN, HIGH);
  digitalWrite(LED_GROEN_PIN, LOW);

  I2C_DHT.begin(DHT_SDA_PIN, DHT_SCL_PIN, 100000);
  Wire.begin(ENS_SDA_PIN, ENS_SCL_PIN, 100000);

  dhtOK = dht20.begin();
  ahtOK = aht20.begin(&Wire);
  ensOK = ens160.begin();

  if (ensOK) {
    ensEnableDataInterrupt();              
    ens160.setMode(ENS160_OPMODE_STD);
  }

  attachInterrupt(digitalPinToInterrupt(ENS_INT_PIN), handleEnsInterrupt, FALLING);

  WiFiManager wm;
  wm.setConnectTimeout(20);          
  wm.setConfigPortalTimeout(120);    
  if (!wm.autoConnect("ESP32_Brandmelder", "12345678")) {
    onlineModus = false;
  } else {
    internetVerbonden = true;
  }

  secureClient.setCACert(rootCertificaten);
  previousSensorMillis = millis();
  if (onlineModus && internetVerbonden) sendPing("online");
}

void loop() {
  unsigned long now = millis();

  // --- KNOP 1 ---
  static bool b1Prev = HIGH;
  static unsigned long lastB1 = 0;
  bool b1 = digitalRead(BUTTON1_PIN);
  if (melderAan && b1 == LOW && b1Prev == HIGH && now - lastB1 > debounceDelay) {
    lastB1 = now;
    if (alarmAan) {
      manualAlarm = false;
      snoozeActive = true;
      snoozeStart = now;
      dfPlayer.pause(); 
      Serial.println(">>> [KNOP] Alarm gedempt (snooze).");
    } else {
      manualAlarm = true;
      Serial.println(">>> [KNOP] Handmatig alarm geactiveerd!");
    }
  }
  b1Prev = b1;

  // --- KNOP 2 ---
  static bool b2Prev = HIGH;
  static unsigned long lastB2 = 0;
  bool b2 = digitalRead(BUTTON2_PIN);
  if (b2 == LOW && b2Prev == HIGH && now - lastB2 > debounceDelay) {
    lastB2 = now;
    onlineModus = !onlineModus;
    if (!onlineModus) {
      if (WiFi.status() == WL_CONNECTED) sendPing("lokaal");   
      WiFi.disconnect(true);
      WiFi.mode(WIFI_OFF);
      internetVerbonden = false;
    } else {
      WiFi.mode(WIFI_STA);
      WiFi.begin();
      unsigned long startConnect = millis();
      while (WiFi.status() != WL_CONNECTED && millis() - startConnect < 5000) delay(100);
      internetVerbonden = (WiFi.status() == WL_CONNECTED);
      if (internetVerbonden) sendPing("online");
    }
  }
  b2Prev = b2;

  if (ensInterruptTriggered) {
    ensInterruptTriggered = false;
    readCo2ForAlarm();
  }

  if (snoozeActive && now - snoozeStart >= snoozeMs) {
    snoozeActive = false;
    Serial.println(">>> [ALARM] Snooze voorbij.");
  }

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
  bool alarmSituatie = melderAan && (overDrempel || manualAlarm);   
  bool nieuwAlarmAan = melderAan && ((overDrempel && !snoozeActive) || manualAlarm);

  // ===== ALARM =====
  if (nieuwAlarmAan) {
    if (!alarmAan || (now - lastSongStart >= songIntervalMs)) {
      playRandomSong();
      lastSongStart = now;
    }
  }
  // ===== MUZIEK VIA WEBSITE =====
  else if (remoteMusic) {
    if (gekozenLiedje > 0 && gekozenLiedje != vorigGekozenLiedje) {
      dfPlayer.play(gekozenLiedje);
      vorigGekozenLiedje = gekozenLiedje;
      musicStarted = true;
      
      Serial.print(">>> [MP3] Liedje gestart via dashboard: ");
      Serial.println(gekozenLiedje);
    }
    else if (!musicStarted) {
      playRandomSong();
      musicStarted = true;
    }
  }
  // ===== ALLES UIT =====
  else {
    if (alarmAan) {
      dfPlayer.pause();
      Serial.println(">>> [MP3] Muziek gestopt.");
    }
    musicStarted = false;
  }
  
  alarmAan = nieuwAlarmAan;

  if (alarmSituatie != vorigeAlarmSituatie) {
    vorigeAlarmSituatie = alarmSituatie;
    alarmEventPending = true;
  }

  if (alarmEventPending && (!alarmPostedBefore || now - lastAlarmPost >= alarmPostMinGapMs)) {
    alarmEventPending = false;
    if (onlineModus && WiFi.status() == WL_CONNECTED) {
      sendMeasurements(temperatureC, eco2ppm, alarmSituatie, snoozeActive, 1);
      lastAlarmPost = millis();
      alarmPostedBefore = true;
    }
  }

  if (tickCount >= samplesPerUpload) {
    if (onlineModus) uploadAverage();
    else { sampleCount = 0; tickCount = 0; }
  }

  static unsigned long lastReconnect = 0;
  if (onlineModus && WiFi.status() != WL_CONNECTED && now - lastReconnect >= 10000) {
    lastReconnect = now;
    WiFi.mode(WIFI_STA);
    WiFi.begin();   
  }

  if (onlineModus && WiFi.status() == WL_CONNECTED && now - lastPing >= pingIntervalMs) {
    lastPing = now;   
    sendPing("online");
  }

  digitalWrite(LED_BLAUW_PIN, melderAan ? HIGH : LOW);

  if (onlineModus) {
    internetVerbonden = (WiFi.status() == WL_CONNECTED);
    digitalWrite(LED_GROEN_PIN, internetVerbonden ? HIGH : LOW);
  } else {
    digitalWrite(LED_GROEN_PIN, LOW);
  }

  delay(2);
}

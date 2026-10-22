#include <WiFi.h>
#include <HTTPClient.h>

const char* ssid = "Custifact";
const char* password = "hetismooiw33r";
const char* serverUrl = "https://brand-melder.onrender.com/api.php";

void setup() {
  Serial.begin(115200);
  WiFi.begin(ssid, password);
  
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nWiFi verbonden!");
}

void loop() {
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(serverUrl);
    http.addHeader("Content-Type", "application/json");

    // Voorbeeld meetwaardes (in de praktijk haal je dit van je sensoren zoals DHT22 / MQ-2)
    float temperatuur = 21.5;
    int co2 = 480;
    int batterij = 92;

    // Maak de JSON string aan
    String httpRequestData = "{\"temperatuur\":" + String(temperatuur) + ",\"co2\":" + String(co2) + ",\"batterij\":" + String(batterij) + "}";

    // Verstuur de POST request
    int httpResponseCode = http.POST(httpRequestData);

    if (httpResponseCode > 0) {
      String response = http.getString();
      Serial.println("HTTP Response code: " + String(httpResponseCode));
      Serial.println("Server antwoord: " + response);
    } else {
      Serial.println("Fout bij verzenden: " + String(httpResponseCode));
    }
    
    http.end();
  }
  
  delay(60000); // Verstuur elke minuut een meting
}
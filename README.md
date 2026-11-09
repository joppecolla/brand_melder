# 🔥 Slimme IoT Brand-Melder (ESP32-S3)

Een intelligent IoT-veiligheidssysteem dat omgevingsfactoren zoals temperatuur en luchtkwaliteit continu bewaakt. De ESP32-S3 verwerkt meetgegevens lokaal, activeert zelfstandig een akoestisch alarm bij overschrijding van ingestelde grenswaarden en synchroniseert meetgegevens en systeemstatus met een cloud-dashboard.

## 🚀 Over het Project

Traditionele rookmelders geven voornamelijk een lokaal akoestisch waarschuwingssignaal en bieden weinig mogelijkheden voor monitoring op afstand.

Deze Smart Brand-Melder breidt dat principe uit met een ESP32-S3, meerdere omgevingssensoren en een online dashboard. Hierdoor worden lokale detectie, autonome alarmverwerking en cloudmonitoring gecombineerd.

Het systeem kan zelfstandig blijven functioneren zonder internetverbinding. Wanneer wifi beschikbaar is, worden meetgegevens en alarmstatussen doorgestuurd naar een PHP-backend die de gegevens opslaat in een Neon PostgreSQL-database.

Via het webdashboard kan de gebruiker de actuele meetwaarden opvolgen, historische gegevens raadplegen, de melder beheren en de CO₂-alarmdrempel aanpassen.

## 🛠️ Technische Specificaties & Hardware

### Componenten

- **Microcontroller:** ESP32-S3 met ingebouwde Wi-Fi en dual-core processing.
- **DHT20:** Digitale temperatuur- en luchtvochtigheidssensor.
- **AHT20:** Tweede temperatuur- en luchtvochtigheidssensor voor aanvullende metingen.
- **ENS160:** Multi-gas luchtkwaliteitssensor die onder andere equivalente CO₂-waarden (eCO₂) levert.
- **Piëzo-buzzer:** Voor het genereren van het lokale akoestische alarmsignaal.
- **Status-LEDs:** Voor het weergeven van de actieve melderstatus en wifi-verbinding.
- **Drukknoppen:** Voor handmatige alarmbediening en het wisselen tussen online en lokale modus.

### Aansluitschema (Pinout)

| Component | Pin op ESP32-S3 | Functie / Bus |
|---|---|---|
| DHT20 (I²C) | GPIO 8 (SDA), GPIO 9 (SCL) | Secundaire I²C-bus |
| AHT20 (I²C) | GPIO 10 (SDA), GPIO 11 (SCL) | Hoofd I²C-bus |
| ENS160 (I²C) | GPIO 10 (SDA), GPIO 11 (SCL) | Hoofd I²C-bus |
| ENS160 Interrupt | GPIO 7 | Detectie van nieuwe sensordata |
| Buzzer | GPIO 1 | Akoestisch alarm |
| Blauwe LED | GPIO 4 | Indicatie melder ingeschakeld |
| Groene LED | GPIO 5 | Indicatie wifi-verbinding |
| Knop 1 | GPIO 16 | Handmatig alarm / 10 seconden dempen |
| Knop 2 | GPIO 15 | Wisselen tussen online en lokale modus |

## 📡 Systeemarchitectuur & Gegevensstroom

### 1. Lokale sensormetingen

De ESP32-S3 leest de aangesloten sensoren standaard elke 30 seconden uit.

De twee temperatuursensoren (DHT20 en AHT20) worden gecombineerd. Wanneer beide sensoren geldige meetwaarden leveren, wordt het gemiddelde berekend. Indien slechts één sensor beschikbaar is, wordt de waarde van die sensor gebruikt.

De ENS160 levert de equivalente CO₂-concentratie (eCO₂), die gebruikt wordt als indicator voor veranderingen in de luchtkwaliteit.

### 2. Lokale alarmverwerking

De microcontroller controleert zelfstandig of de meetwaarden de ingestelde alarmgrenzen overschrijden.

De huidige standaardwaarden zijn:

- **Temperatuur:** hoger dan 35 °C.
- **eCO₂:** hoger dan 1000 ppm.

De CO₂-alarmdrempel kan via het online dashboard aangepast worden. De nieuwe waarde wordt door de backend naar de ESP32-S3 doorgestuurd. De firmware controleert of de ontvangen waarde geldig is (tussen 1 en 5000 ppm) en slaat deze vervolgens op in het interne flashgeheugen via de Arduino `Preferences`-bibliotheek.

Hierdoor blijft de ingestelde CO₂-drempel behouden wanneer de ESP32-S3 opnieuw opstart. Bij het opstarten wordt automatisch de laatst opgeslagen waarde ingelezen. Wanneer nog geen aangepaste waarde beschikbaar is, wordt de standaardwaarde van 1000 ppm gebruikt.

De temperatuurdrempel blijft momenteel vast ingesteld op 35 °C.

Om te vermijden dat het alarm voortdurend in- en uitschakelt bij kleine schommelingen, wordt hysterese toegepast:

- **Temperatuur:** het alarm wordt opnieuw vrijgegeven onder 34 °C.
- **eCO₂:** het alarm wordt opnieuw vrijgegeven wanneer de afgevlakte CO₂-waarde onder de ingestelde drempel min 50 ppm daalt.

De CO₂-waarden worden bovendien afgevlakt met een exponentieel gemiddelde. Hierdoor hebben korte uitschieters minder invloed op de alarmdetectie.

De ENS160 beschikt over een interruptsignaal waarmee nieuwe sensordata sneller verwerkt kunnen worden voor de alarmcontrole.

De buzzer wordt in een afzonderlijke FreeRTOS-taak aangestuurd, zodat het akoestische alarmsignaal onafhankelijk van de netwerkcommunicatie kan blijven functioneren.

### 3. Alarmbediening

Het systeem ondersteunt verschillende bedieningsmogelijkheden:

- **Automatisch alarm:** wordt geactiveerd wanneer een van de meetwaarden de ingestelde alarmgrens overschrijdt.
- **Handmatig alarm:** kan via knop 1 geactiveerd worden.
- **Alarm dempen:** knop 1 dempt een actief automatisch alarm gedurende 10 seconden.
- **Melder in- en uitschakelen:** dit kan via het online dashboard.
- **Lokale modus:** via knop 2 kan de wifi-verbinding uitgeschakeld worden, terwijl de lokale detectie actief blijft.
- **Online modus:** via dezelfde knop kan opnieuw verbinding met wifi gemaakt worden.

### 4. Wi-Fi & HTTPS-communicatie

Wanneer de melder online staat, worden meetgegevens en systeemstatussen via een HTTPS-verbinding naar de backend verstuurd.

De communicatie verloopt via JSON-payloads en de Arduino-bibliotheken `WiFiClientSecure` en `HTTPClient`.

Er zijn verschillende communicatiemomenten:

- **Elke 30 seconden:** een nieuwe sensormeting.
- **Elke 2 minuten:** het gemiddelde van maximaal vier verzamelde meetcycli wordt naar de server verstuurd.
- **Bij een alarmstatuswijziging:** onmiddellijk een afzonderlijke melding naar de website.
- **Elke 15 seconden:** een statusbericht waarmee de serverstatus wordt bijgewerkt en de actuele instellingen worden opgehaald.

De server kan bij deze statuscommunicatie twee belangrijke instellingen terugsturen:

1. De status van de melder (ingeschakeld of uitgeschakeld).
2. De ingestelde CO₂-alarmdrempel.

Wanneer de CO₂-drempel gewijzigd wordt, slaat de ESP32-S3 deze onmiddellijk op in het flashgeheugen.

Wanneer de wifi-verbinding wegvalt, probeert de ESP32-S3 automatisch opnieuw verbinding te maken.

### 5. Cloud Backend & Database

De PHP-backend (`api.php`) ontvangt de JSON-berichten van de ESP32-S3.

De backend verwerkt de binnenkomende gegevens en slaat de meetwaarden en systeemstatus op in een Neon PostgreSQL-database.

De database vormt de centrale opslagplaats voor onder andere:

- Temperatuurmetingen.
- eCO₂-metingen.
- Alarmstatus en alarmreden.
- Status van de melder.
- Verbindingsstatus.
- Historische meetgegevens.
- De configureerbare CO₂-alarmdrempel.

De backend verzorgt ook de communicatie van instellingen terug naar de ESP32-S3, zodat de gebruiker bepaalde functies op afstand kan beheren.

## 💻 Web Dashboard (dashBoard.php)

Het webdashboard biedt een centraal overzicht van de werking en de meetgegevens van de brandmelder.

### Belangrijkste functionaliteiten

- **Actuele meetwaarden:** weergave van temperatuur en equivalente CO₂-concentratie.
- **Historische grafieken:** visualisatie van de meetgegevens over de afgelopen 24 uur.
- **Gemiddelde temperatuur:** de temperatuurweergave is gebaseerd op de beschikbare DHT20- en AHT20-metingen.
- **Configureerbare CO₂-alarmdrempel:** de gebruiker kan via de website de gewenste CO₂-grenswaarde instellen. Deze instelling wordt naar de ESP32-S3 doorgestuurd en lokaal opgeslagen.
- **Flikkervrije updates:** achtergrondverversing via de JavaScript Fetch API.
- **Alarmmeldingen:** visuele waarschuwingen wanneer een alarm actief is.
- **Alarmbeheer:** de melder kan via het dashboard ingeschakeld of uitgeschakeld worden.
- **Systeemstatus:** opvolging van de online- of lokale status en de laatste communicatie met de ESP32-S3.

De website en de microcontroller wisselen niet alleen meetgegevens uit, maar ook configuratie-instellingen. Hierdoor kan de gebruiker de werking van de fysieke melder gedeeltelijk op afstand beheren.

## ⚙️ Installatie & Starten

### 1. Arduino / ESP32 Firmware

1. Open de C++-firmware in de Arduino IDE.
2. Installeer de vereiste libraries:
   - WiFi
   - WiFiClientSecure
   - WiFiManager
   - HTTPClient
   - Preferences
   - Adafruit AHTX0
   - ScioSense ENS160
   - DHT20
3. Controleer de pinconfiguratie en de aangesloten sensoren.
4. Upload de firmware naar de ESP32-S3.
5. Configureer de wifi via het WiFiManager-configuratieportaal indien er nog geen netwerkgegevens opgeslagen zijn.

Bij de eerste configuratie maakt de ESP32-S3 een tijdelijk wifi-toegangspunt aan met de naam `ESP32_Brandmelder` en wachtwoord `12345678`.

De CO₂-alarmdrempel staat standaard ingesteld op 1000 ppm. Een aangepaste waarde kan later via het dashboard ingesteld worden.

### 2. Backend & Database

1. Deploy de PHP-bestanden (`api.php` en `dashBoard.php`) op een geschikte hostingomgeving.
2. Configureer de omgevingsvariabelen voor de verbinding met Neon PostgreSQL.
3. Controleer of de backend JSON-berichten van de ESP32-S3 correct verwerkt.
4. Controleer of de database de ontvangen meetgegevens opslaat.
5. Controleer of de API de melderstatus en CO₂-alarmdrempel correct terugstuurt naar de ESP32-S3.

### 3. Dashboard openen

Open `dashBoard.php` via de browser om de actuele meetgegevens, historische grafieken en systeemstatus te bekijken.

Via het dashboard kan de gebruiker de melder beheren en de CO₂-alarmdrempel aanpassen.

## 👥 Team & Ontwikkeling

Ontwikkeld in het kader van het vak IoT Technologie.

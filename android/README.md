# De Android-app

Dit is een **Trusted Web Activity** (TWA): een dunne schil die
`https://mtl.r010.space` opent in de browser-engine die al op het toestel
staat, schermvullend en zonder adresbalk.

Dat is een bewuste keuze, geen kortere weg. De site is al een progressive web
app met een service worker, dus een eigen WebView-implementatie zou betekenen:
een tweede render-engine om te testen, een tweede offline-cache om te
onderhouden, en een app die achterloopt zodra de site verandert. De TWA gebruikt
dezelfde code, dezelfde cache en dezelfde updates.

## Wat je nodig hebt

* JDK 17
* Android SDK (platform 35, build-tools 35)
* Gradle 8.7 of nieuwer

Er staat geen Gradle-wrapper in de repository. `gradle-wrapper.jar` is een
binair bestand, en een binair bestand in een repository leest niemand na. De
CI-workflow installeert Gradle zelf; lokaal gebruik je je eigen installatie.

## Bouwen

```bash
cd android

# Debug-APK, direct te installeren met adb install
gradle assembleDebug

# Release-APK en -bundle
gradle assembleRelease bundleRelease
```

Het resultaat staat in `app/build/outputs/`.

## Ondertekenen

Maak eenmalig een keystore en bewaar die goed: raak je hem kwijt, dan kun je de
app nooit meer bijwerken onder dezelfde identiteit.

```bash
keytool -genkeypair -v \
  -keystore mtl-release.keystore \
  -alias mtl \
  -keyalg RSA -keysize 4096 -validity 10000
```

Geef het pad mee via omgevingsvariabelen:

```bash
export MTL_KEYSTORE=/pad/naar/mtl-release.keystore
export MTL_KEYSTORE_PASSWORD=…
export MTL_KEY_ALIAS=mtl
export MTL_KEY_PASSWORD=…

gradle assembleRelease
```

Of via `~/.gradle/gradle.properties`, met de sleutels `mtl.keystore`,
`mtl.keystorePassword`, `mtl.keyAlias` en `mtl.keyPassword`.

## De koppeling met de site

Android controleert of de app en het domein bij dezelfde eigenaar horen. Zonder
die controle start de app wél, maar in een Custom Tab mét adresbalk — precies
wat je niet wilt.

1. Vraag de vingerafdruk op van het certificaat waarmee je ondertekent:

   ```bash
   keytool -list -v -keystore mtl-release.keystore -alias mtl | grep -A1 SHA256:
   ```

   De CI-workflow print deze na een release-build ook in het logboek.

2. Zet in het beheer onder **Instellingen → Android**:
   * *Package name*: `space.r010.mtl`
   * *SHA-256 fingerprints*: de vingerafdruk uit stap 1, in de vorm
     `AA:BB:CC:…`. Meerdere waarden scheid je met komma's — dat is nodig
     zodra je Play App Signing gebruikt, want dan tekent Google met een eigen
     certificaat en moet dat er óók in staan.

3. Controleer dat `https://mtl.r010.space/.well-known/assetlinks.json` de
   waarden teruggeeft. Zolang de instellingen leeg zijn, geeft de site bewust
   een lege lijst terug: er is dan simpelweg geen app gekoppeld.

4. Controleer de koppeling met Google's eigen tester:

   ```
   https://digitalassetlinks.googleapis.com/v1/statements:list?source.web.site=https://mtl.r010.space&relation=delegate_permission/common.handle_all_urls
   ```

## Uitgeven in de Play Store

Upload de `.aab` uit `app/build/outputs/bundle/release/`. Let op: met Play App
Signing ondertekent Google de app met een eigen sleutel, dus voeg de
vingerafdruk uit de Play Console (*Setup → App integrity*) toe aan de
instellingen uit stap 2. Doe je dat niet, dan werkt de app die jij test wel en
die uit de Store niet.

## Versienummer

`versionCode` en `versionName` staan in `app/build.gradle`. `versionCode` moet
bij elke upload naar de Play Store omhoog; `versionName` is wat de gebruiker
ziet.

## Wat de app níét doet

* Geen eigen offline-opslag: dat doet de service worker van de site.
* Geen eigen notificaties: die komen van de site, via `DelegationService`.
* Geen eigen rechten behalve netwerk. Vraagt de site om locatie of camera, dan
  handelt de browser die vraag af onder zijn eigen rechtenmodel.

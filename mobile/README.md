# SmartSused – rezidentská aplikácia

Mobilná aplikácia (verzia 1.1.0) je vytvorená vo Flutteri a používa Provider na správu stavu. Pri štarte obnoví prihlásenie zo zabezpečeného úložiska a načíta stav modulov z WordPress REST API; po prihlásení stav obnoví. Položky navigácie a dostupné obrazovky sa zobrazujú podľa zapnutých modulov.

## Spustenie

V koreňovom priečinku `mobile/` spustite:

```sh
flutter pub get
flutter run --dart-define=SMARTSUSED_API_BASE_URL=https://vas-web.sk/wp-json/
```

Adresa musí smerovať na WordPress REST API a končiť na `wp-json/`. Predvolená adresa je produkčné API `https://sb.chudy.online/wp-json/`. Pre lokálny WordPress vývoj ju prepíšte adresou dostupnou z cieľového zariadenia; na Android emulátore nemusí `localhost` smerovať na vývojový počítač.

## Google Sign-In

Prihlásenie cez Google vyžaduje OAuth klienta pre Android/iOS a serverový OAuth klient typu Web application. Serverový klient odovzdajte aplikácii ako:

```sh
flutter run \
  --dart-define=SMARTSUSED_API_BASE_URL=https://vas-web.sk/wp-json/ \
  --dart-define=SMARTSUSED_GOOGLE_SERVER_CLIENT_ID=vas-web-client-id.apps.googleusercontent.com
```

`SMARTSUSED_GOOGLE_SERVER_CLIENT_ID` je povinné pre Google prihlásenie a musí obsahovať presné Web Client ID, ktoré backend používa ako `SMARTSUSED_GOOGLE_CLIENT_ID`. Nepoužívajte Android ani iOS Client ID v tomto parametri. Rovnaké `--dart-define` nastavte aj pri `flutter build apk`/`flutter build appbundle`; aplikácia pri chýbajúcej hodnote Google prihlásenie odmietne namiesto odoslania ID tokenu s nesprávnym audience.

V Google Cloud Console nastavte Android OAuth klienta s application ID `online.chudy.sb.app` a SHA-1 odtlačkom podpisového certifikátu konkrétneho build kľúča. Lokálny debug SHA-1 je viazaný na vývojársky počítač; produkčný release klient musí mať aj SHA-1 release/Play App Signing certifikátu. Pre iOS nakonfigurujte URL scheme s reverzným iOS client ID v `ios/Runner/Info.plist`. OAuth klienti musia patriť do rovnakej Google Cloud konfigurácie a serverový klient musí byť povolený aj na WordPress backende.

Mobilná aplikácia cez `google_sign_in` 7.x inicializuje klienta s povinným `serverClientId`, spustí natívne `authenticate(scopeHint: ['email', 'profile'])` a odošle získaný Google `id_token` ako `id_token` na `POST smartsused/v1/auth/google`; access token nepoužíva. Pri HTTP 403 sa zobrazí slovenská správa o neregistrovanom e-maile. Zrušenie Google výberu účtu používateľom sa považuje za zrušený pokus a nespustí presmerovanie na nástenku. Backend musí overiť audience voči nakonfigurovanému serverovému klientovi.

## API kontrakt

- `POST smartsused/v1/auth/login` s `email` a `password`.
- `POST smartsused/v1/auth/google` s `id_token`.
- Verejný `GET smartsused/v1/modules/status` s boolean hodnotami `communication`, `helpdesk`, `meters` a `polls`.
- `POST smartsused/v1/tickets/create` ako multipart s `title`, `description`,
  `unit_id`, `importance` (`normal`/`emergency`) a voliteľným obrázkom `image`
  (JPEG/PNG/WebP, najviac 5 MiB; iba pri aktívnom Helpdesk module). Mobilná
  obrazovka poruchy umožňuje fotografiu z fotoaparátu alebo galérie; ID jednotky
  musí zodpovedať bytu, ku ktorému má prihlásený rezident prístup.
- Pri aktívnom module ankiet:
  - `GET smartsused/v1/polls/active` vráti aktívne ankety dostupné prihlásenému rezidentovi.
  - `GET smartsused/v1/polls/results?id=<poll_id>` vráti počty a percentá odpovedí `ano`, `nie`, `zdrzal_sa`, aj `hasVoted`.
  - `POST smartsused/v1/polls/vote` prijíma JSON `{"poll_id": 123, "vote": "ano"}`; povolené hodnoty sú `ano`, `nie`, `zdrzal_sa`.

Ankety sa zobrazia v spodnej navigácii iba pri `polls: true`; pri vypnutom module je aj priame otvorenie trasy presmerované na nástenku.

JWT sa ukladá cez `flutter_secure_storage`; Dio interceptor ho posiela ako hlavičku `Authorization: Bearer …`. WordPress musí mať nakonfigurované HTTPS a kompatibilný JWT provider, ktorý overí token a nastaví prihláseného používateľa pre REST API. CORS sa na natívnych aplikáciách neuplatňuje, ale môže byť potrebný pri Flutter Web.

## Kontrola kvality

```sh
dart format lib test
flutter analyze
flutter test
```

Na Windows môžu niektoré Flutter pluginy pri zostavovaní vyžadovať povolený Developer Mode kvôli symlinkom. Android zostavenie môže vyžadovať prijatie licencií Android SDK.

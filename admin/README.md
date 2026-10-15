# SmartSused web application

React, TypeScript, React Router v6, Vite and Tailwind CSS frontend.
The shared Tailwind palette, accessible font stack, and reusable
`.btn-primary` / `.form-input` styles are defined in `tailwind.config.js` and
`src/index.css`.

## Tmavý režim

Celá SPA podporuje svetlý a tmavý režim pre všetky role. Pri prvom otvorení
bez uloženej voľby sa použije systémové nastavenie `prefers-color-scheme`;
používateľ si môže režim zmeniť prepínačom v hlavičke aplikácie. Voľba sa
ukladá do `localStorage` pod kľúčom `smartsused_theme` a má prednosť pred
systémovou preferenciou. Ak ešte nie je uložená manuálna voľba, zmena systémovej
preferencie sa priebežne rešpektuje.

## Development

```sh
npm install
npm run dev
```

The public frontend is served by SmartSused Core at the WordPress site root;
React Router handles `/login`, `/`, `/vlastnici`, and the administrator routes
under `/admin/`. Core returns a minimal HTML shell instead of loading the active
theme and localizes the runtime `smartsusedConfig` object with
`google_client_id` and `rest_url`. Application administration is handled in
React rather than `wp-admin`.

For standalone Vite development, set `VITE_WORDPRESS_REST_URL` and
`VITE_GOOGLE_CLIENT_ID` in `admin/.env`. This file is ignored by Git. In
production, Core provides the REST URL and uses the public Google web client ID
built into the plugin by default; define `SMARTSUSED_GOOGLE_CLIENT_ID` earlier
in `wp-config.php` to override it. The backend verifies Google ID tokens
against that exact audience.

## React administrator dashboard

Accounts with both the WordPress `administrator` role and `manage_options`
capability can use the protected dashboard at `/admin/dashboard`. Its left
sidebar is the only administrator section navigation; the dashboard landing
page shows system-wide statistics, while the license, properties, manager
assignments, Google Auth, and module updates each have their own sidebar route.
The administrator API routes are `GET smartsused/v1/admin/overview`,
`GET/POST smartsused/v1/admin/licence`,
`GET/POST smartsused/v1/admin/houses`, and
`GET/POST smartsused/v1/admin/manager-assignments`, plus
`PUT smartsused/v1/admin/houses/{id}` for editing a published house. Each route checks the
current WordPress user, role, and capability on the server; React route
protection is only an additional UI boundary.

The license summary exposes the current record counts and expiration. The
license service does not currently provide house or owner capacity limits, so
the UI reports the actual counts and identifies limits as unavailable rather
than inferring them. A replacement key is persisted only if online validation
and activation succeed; a failed validation restores the previous license
options. New houses are created as published `smartsused_property` posts, with
address in post content and optional house/entrance/unit counts in post meta.
Manager assignments use the existing `_smartsused_manager_property_id` user
meta consumed by `SmartSused_Manager_Scope`.

The administrator dashboard uses the left sidebar as its only section
navigation; its landing page shows system-wide statistics. Published houses
can be edited from the houses table, with changes sent to the protected REST
API and saved immediately.

The module-updates page checks the Core release through the licensed update
server and can install it using `Plugin_Upgrader`. Core packages use the
`sprava-bytov` update slug and must be published as versioned ZIPs in the
license server's private `smartsused-repository/sprava-bytov/` directory.
SmartSused Polls is also available through the same updater when the license
enables the `polls` module. The page displays each installed add-on version
from the administrator-only `GET smartsused/v1/modules/versions` endpoint.
After a successful update, the updater restores the module's previous
activation state: modules that were active are reactivated, while modules that
were inactive remain inactive.

## Správa ankiet

Správcovia spravujú ankety na `/admin/polls`; rezidenti používajú tú istú
trasu na hlasovanie a zobrazenie výsledkov. Manažérske REST API vracia iba
ankety a domy z priradeného scope, vyžaduje explicitné `property_id` pri
vytvorení a server znova overuje vlastníctvo domu. Dátum expirácie sa odosiela
ako UTC Unix timestamp; anketa prestane prijímať hlasy po uplynutí timestampu.
Výsledky správcu sa obnovujú každých 15 sekúnd.

The `/login` page uses the official Google Identity Services button from
`@react-oauth/google`. Its credential callback sends the Google ID token to
`smartsused/v1/auth/google`; the AuthContext stores the returned SmartSused JWT
and role, then routes the user to the corresponding dashboard. A backend HTTP
403 is shown as a Slovak access-denied message instructing the user to contact
their building manager.

Administrators can manage the public Google Web Client ID at `/admin/google-auth`.
The JWT-protected `GET/POST /smartsused/v1/admin/google-auth` API exposes or
updates only the public client ID and reports the site origin that must be
registered in Google Cloud Console under **Authorized JavaScript origins**.
For production this must be exactly `https://sb.chudy.online` (no path or
trailing slash). A `SMARTSUSED_GOOGLE_CLIENT_ID` constant in
`wp-config.php` takes precedence and locks changes in the UI. Google Identity
Services ID-token login does not use a client secret; the client secret from a
downloaded credentials JSON must never be stored in the React app, returned by
the API, or committed to the repository. Saving the ID validates its format
only; authorized origins must still be configured in Google Cloud Console.
The supplied downloaded credentials file does not include the authorized
JavaScript origins, and the Google Cloud Console must be updated by an account
owner; the application cannot inspect or change that console setting.

## Správa licencie

Administrátor s rolou WordPress `administrator` a oprávnením `manage_options`
spravuje licenciu v React aplikácii na `/admin/licence`. Nový kľúč sa odošle
cez JWT-chránené `POST /smartsused/v1/admin/licence` a uloží sa iba po úspešnom
overení centrálnym endpointom
`https://licencia.chudy.online/wp-json/smartsused-license/v1/activate`.
Pri neplatnom kľúči alebo nedostupnom licenčnom serveri zostane predchádzajúca
licencia zachovaná. Súhrnný stav a počet domov/vlastníkov poskytuje
`GET /smartsused/v1/admin/licence`; kapacitné limity sa nezobrazujú, pretože
centrálny licenčný kontrakt ich neposkytuje.

For production cross-origin access, add only the exact React app origin to
`wp-config.php`:

```php
define(
    'SMARTSUSED_ALLOWED_ORIGINS',
    array( 'https://sb.chudy.online' )
);
```

## Priradenie správcu a izolácia domu

Klientska aplikácia beží na `https://sb.chudy.online`. Dashboard správcu je na
koreňovej trase `/`; správa vlastníkov je na `/vlastnici`, jednotky a ďalšie
oblasti používajú statické
trasy `/admin/units`, `/admin/owners` a `/admin/notices`. Trasy neobsahujú ID
príspevkov; vybraný záznam zostáva v stave React komponentu.

Komponent `AdminNotices` vyžaduje aktívny samostatný add-on SmartSused Notices.
Načíta správcovské oznamy cez `GET /smartsused/v1/notices/manage`, vytvorí ich
cez `POST /smartsused/v1/notices/create` a používa správcovsky overené
`PUT`/`DELETE /smartsused/v1/notices/{id}`. Add-on kontroluje každý dom voči
priradeniam správcu; formulár ponúka iba domy a vchody dostupné cez REST API.

Komponent `TechnicalPassport` používa add-on SmartSused Technical Passport na
`/admin/technical-passport`. Správcovia spravujú plán revízií a dodávateľov;
vlastníkom sa zobrazuje iba zoznam dodávateľov z ich bytových domov. Komponent
volá `GET /smartsused/v1/technical/revisions` a
`GET /smartsused/v1/technical/vendors`, pričom vytvorenie, úprava a odstránenie
používajú zodpovedajúce `POST`, `PUT` a `DELETE` trasy add-onu. Add-on musí byť
aktívny a licencia Core musí povoľovať modul `revision`.

Administrátor s rolou WordPress `administrator` a oprávnením `manage_options`
priradí účet `smartsused_manager` k jednému alebo viacerým publikovaným domom
v React aplikácii na `/admin/manager-assignments`. Priradenia sú uložené v user
meta `_smartsused_manager_property_id` (staršie samostatné ID zostáva
podporované); načítanie aj zápis vyžadujú administrátorskú rolu a capability.
Správca bez platného priradenia nevidí dáta a manager REST operácie zlyhávajú
zatvorene.

WordPress queries pre domy, jednotky a ankety a štandardné CPT REST trasy pre
domy a jednotky sú obmedzené na priradené domy. Vlastnícke REST endpointy, importy, šablóny,
priradenia rezidentov, stiahnutie zabezpečených súborov a výsledky ankiet
kontrolujú rovnakú hranicu na serveri, a to aj pri priamom ID v požiadavke.
`smartsused_manager`, `smartsused_owner` a `smartsused_tenant` sú odhlásené a
presmerované na `https://sb.chudy.online/` pri pokuse otvoriť WordPress
administráciu alebo prihlasovaciu obrazovku. Administrátorské účty nie sú
týmto odhlásením ovplyvnené.

The API also permits `http://localhost:5173` and `http://127.0.0.1:5173` for
local development. The same list can be extended with the
`smartsused_rest_allowed_origins` filter. CORS is not authentication; API
endpoints must continue validating the JWT and user capabilities.

## Pozvanie nájomcu vlastníkom

Vlastník odošle `POST /wp-json/smartsused/v1/tenant/invite` s JSON údajmi
`email` a `unit_id`. Požiadavka vyžaduje platný JWT vlastníka, ktorý má aktívny
vzťah vlastníka ku konkrétnej publikovanej jednotke. Na jeden účet možno
odoslať najviac päť pozvánok za hodinu. Existujúci e-mail nemožno pozvať ako
nový účet.

Pozvánka má náhodný 256-bitový token, v databáze sa uchováva len jeho SHA-256
hash a platí 48 hodín. E-mail obsahuje odkaz `/register-tenant?token=...`;
stránka najprv overí token a až potom zobrazí pozvaný e-mail alebo prijme heslo.
Registrácia je verejný `POST /wp-json/smartsused/v1/tenant/register` s
`token` a heslom s minimálne 12 znakmi. Účet aj vzťah `tenant` k pozvanej
jednotke sa vytvoria v databázovej transakcii; registrácia zamyká e-mail, aby
súbežné pozvánky nevytvorili duplicitný účet. Token možno použiť iba raz.
Všetky tabuľky zúčastnené na zápise musia používať InnoDB.

React komponent `ManageTenants` je zobrazený vlastníkom na rezidentskej
nástenke. Načíta vlastnícke jednotky a priradených nájomcov z
`GET /wp-json/smartsused/v1/tenant/units`, odošle pozvánku a odoberie iba
vzťah tenant–jednotka cez `DELETE /wp-json/smartsused/v1/tenant/{user_id}/unit/{unit_id}`
(používateľský účet zostáva zachovaný). Verejná stránka `/register-tenant`
overuje e-mail pozvánky cez `POST /tenant/invite/validate` a dokončí registráciu
heslom alebo Google ID tokenom cez `/tenant/register`; Google e-mail sa musí
zhodovať s adresou z pozvánky.

## Hromadný import obyvateľov

Správca môže nahrať Excel alebo CSV cez `POST
/wp-json/smartsused/v1/import/residents` (kompatibilný alias je
`/wp-json/smartsused/v1/import/owners`).
Požiadavka musí byť `multipart/form-data`, obsahovať súbor v poli `file` a
odoslať JWT v hlavičke `Authorization: Bearer <token>`. CSV používa UTF-8,
bodkočiarku a hlavičku v presnom poradí:

```text
Meno;Priezvisko;E-mail;Telefón;Číslo bytu;Typ obyvateľa
```

Spoločný súbor `smartsused-obyvatelia.xlsx` obsahuje hárok **Obyvatelia**.
V stĺpci **Typ obyvateľa** zvoľte hodnotu **Vlastník** alebo **Nájomca** z
Excel rozbaľovacieho menu; jednotku vyberte zo zoznamu publikovaných bytov.
Správcovi priradenému k domu sa ponúkajú iba jednotky tohto domu. Pri
rovnakých číslach bytov v rôznych domoch sa v ponuke pridá názov domu, aby bolo
priradenie jednoznačné; technické ID bytu netreba poznať.

Vlastník dostane rolu `smartsused_owner` a vzťah `owner`, nájomca rolu
`smartsused_tenant` a vzťah `tenant`. Import prijíma súbory do 5 MiB a najviac
5 000 dátových riadkov. Nový účet dostane náhodné heslo a používateľovi sa
odošle uvítací e-mail s odkazom na nastavenie hesla. Pri existujúcom
používateľovi sa pridá príslušná rola bez odstránenia ostatných rolí.
Nepovinný telefón sa uloží do usermeta `smartsused_phone`.

Odpoveď obsahuje počty importovaných, duplicitných, chybných riadkov a
neodoslaných e-mailov, ako aj najviac 100 detailov. Osobné údaje ani
prihlasovacie údaje sa v zozname chýb nevracajú.

Importná zóna `CSVImportZone` sa zobrazuje iba na stránke vlastníkov a prijíma
`.xlsx` aj `.csv`. Štruktúra importu závisí od počtu domov priradených
správcovi: pri jednom dome je hlavička uvedená vyššie a jednotka sa vyberá z
dropdownu. Pri viacerých domoch sa na začiatok pridá stĺpec `Názov bytového
domu` s dropdownom dostupných domov a hlavička je
`Názov bytového domu;Meno;Priezvisko;E-mail;Telefón;Číslo bytu;Typ obyvateľa`.
Číslo bytu sa v tomto režime zadáva ako text a backend ho overí voči
publikovaným jednotkám zvoleného domu. Šablónu poskytuje `GET
/wp-json/smartsused/v1/import/template?type=residents`; `type=owners` zostáva
kompatibilným aliasom. Importné endpointy sú
`POST /smartsused/v1/import/properties`, `POST /smartsused/v1/import/units`
a `POST /smartsused/v1/import/residents` (alias `owners`). XLSX súbor musí
obsahovať jeden viditeľný hárok a najviac 5 000 dátových riadkov; skrytý
pomocný hárok uchováva hodnoty rozbaľovacích zoznamov. Backend vyžaduje
zjednotenú hlavičku v uvedenom poradí. Čítanie a tvorbu
Excel súborov zabezpečuje pribalená knižnica PhpSpreadsheet; na serveri musia
byť povolené PHP rozšírenia ZIP, GD a Fileinfo.

Excel nemá štandardnú podporu regulárnych výrazov vo validácii buniek.
Šablóna preto používa vlastné vzorce na kontrolu e-mailu (zavináč a doména)
a telefónu (7–20 číslic, prípadne jedno úvodné plus). Excel šablóna jednotiek
navyše kontroluje, že poschodie je celé číslo. Backend všetky polia znovu
validuje pri importe.

## Ručná správa bytových domov a jednotiek

Administrácia je rozdelená do samostatných stránok **Bytové domy**
(`/admin/properties`), **Bytové jednotky** (`/admin/units`) a **Vlastníci**
(`/admin/owners`). Stránka domov načítava publikované domy cez
`/wp/v2/smartsused_property`; tieto štandardné CPT trasy sú dostupné iba
administrátorom a manažérom s overeným JWT, pričom manažéri vidia iba priradené
domy. Správca ich môže pridávať/upravovať ručne alebo importovať CSV
`nazov_domu;adresa`. Jednotky sa spravujú samostatne a obsahujú
filter podľa domu. REST polia `property_id`, `floor` a `entrance` na jednotke
mapujú ID domu na `post_parent` a hodnoty poschodia/vchodu na postmeta
`_poschodie` a `_vchod`. Dom musí byť publikovaný.

Rezidentský formulár porúch načítava iba vlastné bytové jednotky z
`GET /smartsused/v1/tenant/units`; štandardné CPT REST trasy sú pre vlastníkov
a nájomcov zakázané.

Na stránke vlastníkov je vyhľadateľný zoznam vlastníkov a ich bytov, spoločný
Excel/CSV import obyvateľov a formulár **Pridať vlastníka manuálne**. Formulár načíta domy z WordPress REST
API a následne ponúkne iba voľné jednotky cez
`GET /smartsused/v1/owners/units?property_id=<id>`. Zápis vykoná
`POST /smartsused/v1/owners/create`; endpoint je dostupný iba manažérovi s
overeným JWT. Ak e-mail už patrí používateľovi, použije sa existujúci účet;
inak sa vytvorí účet s náhodným heslom. V oboch prípadoch sa k účtu priradí
rola vlastníka a byt. Uvítací e-mail obsahuje WordPress odkaz na bezpečné
nastavenie hesla.

Po importe rezidenta aj po manuálnom pridaní vlastníka odošle Core spoločnú
funkciu `smartsused_send_welcome_email($user_id, $role)`. Vlastník dostane
tmavomodrý e-mail a nájomca smaragdovo zelený e-mail s obsahom prispôsobeným
ich roli. Hlavné tlačidlo otvorí `https://smartsused.sk/login`; samostatný
jednorazový odkaz v tele správy vedie na bezpečné nastavenie hesla cez
WordPress. URL aplikácie možno upraviť filtrom `smartsused_welcome_app_url`
(filter musí vracať HTTPS URL).

Úprava vlastníka zmení osobné údaje účtu. Akcia odstránenia odoberie iba jeho
vzťah vlastníka k jednotkám v rozsahu správcu; účet a väzby v iných domoch
zostávajú zachované. V tabuľke jednotiek možno jednotku odstrániť po potvrdení.

## Hromadný import bytových jednotiek

Správca môže nahrať XLSX alebo CSV cez `POST /wp-json/smartsused/v1/import/units`.
Požiadavka musí byť `multipart/form-data`, obsahovať súbor v poli `file` a
odoslať overený JWT v hlavičke `Authorization: Bearer <JWT>`. CSV používa
UTF-8, bodkočiarku a hlavičku v tomto poradí:

```text
nazov_domu;cislo_bytu;poschodie;vchod
```

Jednotka sa vytvorí pod publikovaným bytovým domom nájdeným podľa názvu:
`post_parent` je jeho ID a titulok má tvar `Vchod [vchod], Byt [cislo_bytu]`.
Meta `_poschodie` a `_vchod` sa uložia v tej istej databázovej transakcii.
Excel šablóna navyše kontroluje celé číselné poschodie.
Duplicity v rámci domu sa preskočia. Súbor môže mať najviac 5 MiB a 5 000
riadkov; odpoveď obsahuje počty vytvorených, duplicitných a chybných riadkov
a najviac 100 detailov chýb. Import odmietne spustiť zápis, ak tabuľky
`posts` alebo `postmeta` nepodporujú InnoDB transakcie. Každý riadok sa
potvrdzuje samostatne; databázový advisory lock serializuje súbežné zápisy
tej istej jednotky.

Inside the SmartSused WordPress admin page, the REST URL and REST nonce come
from `wp_localize_script()` and take precedence over the Vite environment
variables.

## Zabezpečené fotografie

Na zobrazenie chránenej fotografie použite `SecureImage` a odovzdajte
vygenerovaný názov súboru z API:

```tsx
import { SecureImage } from "./components/SecureImage";

<SecureImage
  src={file.file_name}
  alt="Fotografia vodomeru"
  className="h-48 w-full object-cover"
/>;
```

Komponent sťahuje obrázok cez zdieľaného `apiClient`, ktorý pridáva JWT
hlavičku, a po použití uvoľní dočasnú Blob URL. Backend musí povoliť prístup
danému používateľovi k súboru a jednotke, ku ktorej patrí.

## Rezidentská anketa

`PollWidget` zobrazuje anketu, prijíma počiatočné počty hlasov a po úspešnom
odoslaní znovu načíta výsledky z API. `results` obsahuje počty hlasov, nie
percentá:

```tsx
import { PollWidget } from "./components/PollWidget";

<PollWidget
  id={123}
  question="Súhlasíte s navrhnutou úpravou vstupu?"
  description="Hlasovanie je interné a nezáväzné."
  hasVoted={false}
  results={{ ano: 12, nie: 4, zdrzal: 1 }}
/>;
```

Komponent posiela hlas na `POST /smartsused/v1/polls/vote` a číta aktuálne
počty z `GET /smartsused/v1/polls/results?id=<id>` cez zdieľaného Axios klienta
s JWT autorizáciou.

Stránka `/admin/polls` načíta aktívne ankety a výsledky cez REST API; odkaz
„Ankety“ sa zobrazí manažérom a rezidentom, keď verejný endpoint
`GET /smartsused/v1/modules/status` vráti `polls: true`. Ten istý endpoint
vracia `core_version`, ktorú rozhranie používa v pätičke namiesto napevno
zapísaného čísla.

Add-on SmartSused Polls musí byť aktívny a jeho modul `polls` povolený v
licencii Core. Správcovské endpointy ankiet obmedzujú vytvorenie a čítanie
výsledkov na domy priradené správcovi; hlasovanie aj čítanie rezidenta sa
overujú cez jeho väzbu k publikovanej jednotke.

## Production build

```sh
npm run build
```

Vite writes `assets/build/smartsused-admin.js` and
`assets/build/smartsused-admin.css` in the plugin root. The WordPress plugin
enqueues them only on its SmartSused page. That page uses hash-based routing
under `wp-admin`; the standalone app uses browser-history routes and its web
server must rewrite unknown paths to `index.html`.

The WordPress admin menu entry requires an authenticated account with the
`read` capability. Application routes separately restrict manager and resident
views by the role returned by the authentication API. Backend endpoints must
continue enforcing authorization independently of frontend route guards.

## Aktualizácie add-onov

SmartSused Core kontroluje nainštalované add-ony Notices, Helpdesk a Technical
Passport cez natívne WordPress aktualizačné filtre. Kontrola sa vykonáva voči
`https://licencia.chudy.online/wp-json/smartsused-license/v1/update-check`; klient pošle
doménu, slug a nainštalovanú verziu a licenčný kľúč posiela v HTTPS hlavičke
`Authorization: Bearer ...`. Licencia musí mať konkrétny modul povolený.
Server vracia iba novšiu verziu a krátkodobý jednorazový balík.

Manuálne spustenie z dôveryhodného React rozhrania používa
`POST /smartsused/v1/modules/update` s `{"slug":"smartsused-helpdesk"}`.
Endpoint používa WordPress `Plugin_Upgrader` a je dostupný výhradne účtu s
`update_plugins` capability; cookie-authenticated požiadavky musia niesť
WordPress REST nonce. Správcovská rola bytových domov toto oprávnenie nemá.
Po dokončení aktualizácie pluginu Core vymaže `update_plugins` site transient
a obnoví WordPress plugins cache, aby sa načítali nové hlavičky verzií. Core
následne porovná verziu deklarovanú centrálnym manifestom s hlavičkou práve
nainštalovaného pluginu a neoznačí aktualizáciu za úspešnú pri nesúlade.
Príklad volania cez zdieľaný Axios klient:

```ts
await apiClient.post("smartsused/v1/modules/update", {
  slug: "smartsused-helpdesk",
});
```

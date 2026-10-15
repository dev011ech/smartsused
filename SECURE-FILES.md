# Zabezpečené súbory SmartSused

Pri aktivácii SmartSused Core vytvorí `wp-content/smartsused_secure_uploads`, Apache pravidlá na zákaz priameho prístupu a databázové tabuľky s prefixom aktuálnej inštalácie WordPressu:

- `<prefix>smartsused_unit_relationships` eviduje väzby rezidentov na jednotky. `resident_type` je `owner` alebo `tenant`; unikátny index bráni duplicitnej väzbe používateľ–jednotka.
- `<prefix>smartsused_secure_files` eviduje náhodný názov súboru, jednotku a overený MIME typ.
- `<prefix>smartsused_invites` uchováva hash jednorazového tokenu pozvánky,
  e-mail, jednotku, pozývajúceho a časové údaje. Surový token sa do databázy
  neukladá.

Prefix sa berie z `$wpdb->prefix`, preto nemusí byť `wp_`. Pri deaktivácii pluginu sa súbory ani tabuľky nemažú.

## Priradenie rezidenta k jednotke

Z administračnej logiky, ktorá už overila oprávneného správcu, možno vytvoriť alebo zmeniť väzbu takto:

```php
$result = SmartSused_Secure_Files::assign_resident_to_unit(
	$user_id,
	$unit_id,
	'owner' // alebo 'tenant'
);

if ( is_wp_error( $result ) ) {
	// Spracujte a zobrazte chybu podľa konvencií administračnej obrazovky.
}
```

Metóda vyžaduje aktuálneho používateľa s rolou `smartsused_manager` alebo capability `manage_options`, skontroluje rolu rezidenta aj CPT jednotky a uloží väzbu do relačnej tabuľky.

## Bezpečné uloženie

Volajte helper po úspešnom nahratí a kontrole WordPress uploadu:

```php
$stored_name = smartsused_move_file_to_secure_storage(
	$_FILES['document']['tmp_name'],
	$_FILES['document']['name'],
	$unit_id
);

if ( is_wp_error( $stored_name ) ) {
	// Spracujte chybu a nevracajte internú cestu k súboru klientovi.
}
```

Povolené sú PDF, JPEG, PNG a WebP súbory; prípona sa porovnáva so zisteným MIME typom, veľkosť je obmedzená limitom WordPressu a názov v úložisku používa 256-bitovú náhodnú hodnotu. Tretí argument `unit_id` je voliteľný: bez neho ide o súbor prístupný iba správcovi. Súbor nikdy nesprístupňujte pod pôvodným názvom ani URL cestou.

## Stiahnutie a JWT

Endpoint je `GET /wp-json/smartsused/v1/files/download?file=<vygenerovany_nazov>` a vracia súbor ako prílohu bez cache. Správca má prístup ku všetkým súborom; vlastník alebo nájomca iba k súborom priradeným k jednotke, ku ktorej má záznam v tabuľke vzťahov.

Administrátor WordPressu má prístup ku všetkým súborom. Správca SmartSused má prístup iba k súborom jednotiek v domoch priradených administrátorom; vlastník alebo nájomca iba k súborom jednotiek, ku ktorým má záznam v tabuľke vzťahov.

Endpoint zámerne zlyhá zatvorene, ak JWT autentifikačný provider nepotvrdí token. Provider musí kryptograficky overiť podpis a platnosť JWT, nastaviť aktuálneho používateľa WordPressu a vrátiť jeho ID cez filter `smartsused_verified_jwt_user_id`. Samotná existencia hlavičky `Authorization` sa nepovažuje za overenie JWT. Neplatný token aj zamietnutý prístup vrátia HTTP 403 a správu „Nemáte oprávnenie na zobrazenie tohto súboru.“.

Správcovský účet môže byť priradený k jednému alebo viacerým publikovaným domom
cez **SmartSused → Priradenie správcov**. Nastavenie je v user meta
`_smartsused_manager_property_id` (podporuje aj staršie samostatné ID) a
vyžaduje rolu `administrator` aj `manage_options`. Priame požiadavky na
jednotky, vlastníkov, importy a výsledky ankiet sa kontrolujú na serveri a
nemôžu rozšíriť toto priradenie zmenou ID.

## Pozvánky nájomcov

`POST /wp-json/smartsused/v1/tenant/invite` vyžaduje overený JWT vlastníka
a existujúci vzťah `owner` k publikovanej jednotke. Token pozvánky je
kryptograficky náhodný, do databázy sa ukladá iba SHA-256 hash a jeho platnosť
je 48 hodín. Verejný `POST /wp-json/smartsused/v1/tenant/register` prijíma
token a heslo alebo overený Google ID token; Google e-mail sa musí presne
zhodovať s adresou pozvánky. E-mail a jednotku registrácia preberá výhradne
z platnej pozvánky. Vytvorenie
účtu, vzťahu nájomcu a spotrebovanie tokenu prebieha v transakcii. Tabuľky
pozvánok, používateľov, usermeta a vzťahov musia používať InnoDB, inak
registrácia zlyhá zatvorene. Registrácia zamyká e-mail cez databázový advisory
lock a kontroluje ho znova pred vytvorením účtu, aby súbežné pozvánky nevytvorili
viac účtov pre rovnakú adresu.

Vlastník môže načítať svoje jednotky/nájomcov cez
`GET /wp-json/smartsused/v1/tenant/units`. Odobratie nájomcu maže iba vzťah
konkrétneho používateľa k jednotke, nikdy používateľský účet.

JWT provider musí používať `smartsused_get_jwt_secret()` na podpisovanie aj overovanie tokenov. Funkcia zamietne localhost a lokálne TLD, pri chýbajúcom platnom cache si vyžiada licenciu cez HTTPS z `https://licencia.chudy.online/wp-json/smartsused-license/v1/activate`, pričom odošle doménu aj kľúč z administračných nastavení. Odpoveď sa uloží len pri HTTP 200, stave `active` a platnom dátume exspirácie. Pri offline stave alebo zamietnutí licencie zlyhá zatvorene. Endpoint `POST /smartsused/v1/auth/login` túto kontrolu vykoná ešte pred vydaním tokenu.

Apache pravidlá chránia adresár pred priamym webovým prístupom. Pred produkčným nasadením overte pravidlá webservera a zakážte vykonávanie skriptov v adresári úložiska.

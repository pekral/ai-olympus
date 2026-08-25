---
title: Jak jsem svému Laravel projektu dal AI vývojový tým
published: false
canonical: true
tags: laravel, php, ai, opensource
---

> **Koncept — nepublikováno.** Tohle je kanonická verze; její domov je pekral.cz. Anglický překlad
> v `launch-article.en.md` míří přes `canonical_url` sem, takže si obě verze nekonkurují ve
> vyhledávání. Demo nahrávka zatím chybí, viz značku dál v textu.

Včera v repozitáři přistál pull request, který smazal 4 782 řádků a přidal 106. Patnáct tříd pryč,
tři testovací soubory pryč, pět dokumentů přepsaných. Sada skončila na 636 prošlých testech a pokrytí
100 % a code review vrátila nulu kritických a nulu závažných zjištění.

Nenapsal jsem z toho nic. Napsal jsem jednu větu do komentáře na GitHubu:

> ai-olympus bash-guard chci úplně smazat z repa!

Ten komentář popíral issue, které jsem sám založil tři minuty předtím a ve kterém jsem místo toho
žádal tři opatrné opravy. Agent si ten rozpor všiml, vzal novější instrukci jako platnou, napsal to
do popisu pull requestu — a funkci smazal.

Tenhle článek je o tom, jak ta sestava funguje, proč její samozřejmá verze nefunguje, a co pořád
neumí.

## Problém nikdy nebyl v kódu

Psal jsem do review pořád stejné poznámky. Ne zajímavé — stejné. Metoda se šesti parametry, která
měla brát datový objekt. Dotaz slepený v kontroleru místo v repozitáři. `catch (\Throwable)`, který
spolkl chybu, o níž se už nikdo nikdy nedozví. Nový test, co ověří happy path a nechá nepokrytou
právě tu větev, kvůli které vznikl.

Ani jedna z těch věcí nepotřebovala seniorního vývojáře, aby si jí všiml. A všechny ho potřebovaly,
protože pravidla žila v mojí hlavě a v hlavách dvou dalších lidí — nikde, kde by je přečetl stroj.

Obvyklá odpověď je soubor `CLAUDE.md` s vlastními konvencemi. Zkusil jsem to první. Funguje to do
druhého projektu, a pak máte dva soubory, které byly identické v den, kdy jste jeden zkopírovali, a
od té doby už ne. U čtvrtého projektu neudržujete standardy, ale kopie standardů.

## Proč jeden velký prompt nefunguje

První skutečný pokus byl jeden agent s velmi dlouhým promptem: tady jsou standardy, tady je issue,
naimplementuj to a zkontroluj si svou práci.

Selhalo to konkrétně a opakovatelně. Agent něco naimplementoval, pak to zkontroloval a pak
prohlásil, že je to dobré. Samozřejmě. Kontroloval úvahu, kterou právě vyprodukoval, a měl ji přitom
pořád v kontextu. Každé rozhodnutí, které při psaní udělal, bylo při čtení pořád tou nejdostupnější
odpovědí.

Reviewer, který ten kód napsal, není reviewer. To není omezení AI — právě proto jsme vymysleli pull
requesty.

Tak se roster rozdělil. Šest agentů, šest rolí, a žádný agent nedělá dvě z nich:

- **`zeus`** vlastní backlog, ne změnu. Otriáduje otevřené issues do obhajitelného pořadí a zadání,
  které je na jeden pull request příliš velké, rozdělí na samostatně doručitelné. Neimplementuje,
  nereviewuje ani nemerguje.
- **`daedalus`** rozpozná zdroj, rozhodne cestu a dispatchuje. Drží `Task`, `Read`, `Glob`, `Grep`,
  `Bash`. Kód nikdy nepíše.
- **`hephaestus`** implementuje. Je to jediný agent, který drží `Write` a `Edit`.
- **`athena`** reviewuje — kvalitu kódu, architekturu a bezpečnost v jednom průchodu — a řídí
  opravnou smyčku až do konvergence. `Write` ani `Edit` nedrží.
- **`argus`** je jediný agent, který spouští aplikaci — API přes skutečné HTTP, UI ve skutečném
  prohlížeči — a pro každé akceptační kritérium vrací verdikt: splněno, nesplněno, částečně, nebo
  zablokováno. Kód needituje.
- **`hermes`** napíše lidsky čitelný report, jakmile smyčka zkonverguje.

To rozdělení má cenu kvůli tomu, co každý agent **nemůže**, ne kvůli tomu, co může. Když `athena`
čte diff od `hefaista`, čte kód, který nenapsala, a nemá v kontextu ani jednu autorovu úvahu. Něco
najde. V pull requestu z úvodu našla dvě věci, obě moje, obě skutečné, a obě opravené ještě před
publikováním review.

## Proč Composer plugin, a ne zkopírovaný soubor

Standardy se distribuují jako Composer balíček: 22 souborů s pravidly a 54 skillů, které binárka
nainstaluje do `.claude/rules` a `.claude/skills`.

```bash
composer require pekral/ai-olympus --dev
vendor/bin/ai-olympus install --force
```

Důvod je drift. Zkopírovaný `CLAUDE.md` nemá verzi, nemá changelog a nemá jak říct, jestli projekt,
který jste dnes ráno otevřeli, obsahuje pravidlo, které jste přidali minulý týden. Záznam
v `composer.lock` má všechno tři. Když se pravidlo změní, `composer update` ho přinese — a každý
projekt, který neaktualizoval, přesně ví, na jaké verzi stojí.

Od tohohle týdne existuje druhá instalační cesta, pro projekty bez Composeru:

```text
/plugin marketplace add pekral/ai-olympus
/plugin install ai-olympus@ai-olympus
```

Ta má poctivou mezeru, kterou má cenu říct nahlas, ne obejít: Claude Code čte z adresáře pluginu
`skills/` a `agents/`, ale nečte ani `rules/`, ani `CLAUDE.md`. Pro projektový instrukční soubor,
který se načítá do každého sezení, žádný pluginový mechanismus neexistuje. Pravidla proto cestují
jedním příkazem navíc, `/ai-olympus:install-rules`, a obě cesty nejsou rovnocenné. Na PHP
projektu je Composer pořád ta lepší.

## Bezpečnostní návrh je hlavně o tom, co je odepřené

Tři věci brání tomu, aby to byl stroj, který vám přepisuje repozitář, když jste na obědě.

**Read-only agenti jsou read-only ve frontmatteru.** `athena`, `hermes` i `daedalus` nesou
`disallowedTools: Write, Edit`. To vynucuje harness, ne dobrá vůle agenta. `hephaestus` nese
`disallowedTools: WebSearch, WebFetch` z opačného důvodu: agent, který zapisuje soubory, nemá co
stahovat cizí URL.

**`composer build` je branka, ne doporučení.** Spustí instalátor, pak pět fixerů, pak deset
kontrolorů — PHPCS, Pint, Rector, PHPStan, bezpečnostní audit, self-testy shell skriptů, ShellCheck
a Pest s `--min=100`. Nic se nepushne, dokud to neskončí nulou. Těch 100 % je nad změněnými řádky a
je to důvod, proč oprava přichází i s testem, který ji dokazuje.

**Nic se nemerguje samo.** Pull request se otevírá jako Draft a Draftem zůstává, dokud review
nezkonverguje na nulu kritických a nulu závažných zjištění. Merge sám je zvláštní krok, který
proběhne jen tehdy, když jsem si ho v tom běhu vyžádal — ze zadání „vyřeš tohle issue" nevyplývá.

## Jeden kompletní běh od začátku do konce

Tady je ten běh z úvodu celý.

Založil jsem issue #265: balíček dodával volitelný `PreToolUse` hook, který kontroloval každý Bash
příkaz proti per-agent politice, a pořád přerušoval běžnou práci. Každý příkaz, jehož jméno programu
vzniká za běhu — `"$BIN" --version`, `$(which php) -v` — dostal verdikt *ask*, a nebylo jak to
vypnout, protože opt-in přepínač nikdy nedostal svůj protějšek. Navrhl jsem tři opravy.

Pak jsem si to přečetl znovu a napsal ten jednořádkový komentář: smazat.

Co běh udělal:

1. Přečetl issue, přečetl komentář a vzal komentář jako aktuální požadavek — tři opravy z těla issue
   se staly bezpředmětnými místo toho, aby se pro jistotu udělaly taky.
2. Smazal 15 tříd, podpříkaz `bash-guard`, napojení na stdin, které existovalo jen kvůli němu, čtyři
   chybové factory metody a argument `$processExecutor`, který existoval jen kvůli install-time
   smoke testu toho hooku.
3. Přepsal pět dokumentů tak, aby po odstranění neslibovaly ochranu, která už neexistuje.
4. Spustil review. Dvě závažná zjištění, obě spíš o poctivosti odstranění než o jeho správnosti:
   postup, jak hook odinstalovat, zmizel spolu s dokumentací přepínače, a changelog zlehčoval, co
   zastaralá položka hooku dnes vlastně dělá.
5. Druhé zjištění ověřil spuštěním, ne úvahou:

   ```console
   $ php bin/ai-olympus bash-guard </dev/null
   Unknown command: bash-guard
   $ echo $?
   1
   ```

   Nenulový exit jiný než 2 je neblokující chyba hooku, takže příkaz proběhne — ale chyba se vypíše
   při **každém** volání Bashe, dokud se položka nesmaže. To je horší, než changelog tvrdil, takže
   to teď říká changelog i `SECURITY.md`, včetně postupu úklidu.
6. Obojí opravil, znovu prošel brankou, publikoval review na 0/0/0 a zamergoval.

```console
$ composer build
Tests:    636 passed (4576 assertions)
Total: 100.0 %
$ git show --stat e26995b | tail -1
36 files changed, 106 insertions(+), 4782 deletions(-)
```

<!-- SEM PATŘÍ DEMO NAHRÁVKA — asciinema embed, 45–75 s, viz issue #106.
     Nepublikovat článek, dokud nahrávka nebude na místě: odstavec výše
     popisuje běh, který by si čtenář měl umět přehrát. -->

Co jsem nečekal, je krok 4. Zjištění z review nebyla o kódu — smazání bylo mechanické. Byla o tom,
jestli dokumentace říká pravdu o důsledku. Přesně tu review bych v pátek v šest večer přeskočil.

## Co to neumí

- **Jen Claude Code.** Žádný Cursor, Copilot ani Windsurf. Přepínač `--editor` byl odstraněn, místo
  aby zůstal napůl podporovaný.
- **Potřebuje placený plán Claude.** Šest agentů, opravná smyčka až o třech iteracích a plný
  lokální build před každým pushem není zátěž pro free tier.
- **Bash hranice je advisory, ne vynucená.** Každý agent drží `Bash` a `Bash` v sobě obsahuje
  zápis i síť bez ohledu na to, co říká `disallowedTools`. Vlastní instrukce „read-only" agenta
  nezastaví `cat > file`. Balíček to píše v `SECURITY.md`, místo aby předstíral opak — a pull
  request z úvodu je přesně to, co se stalo, když jsem to zkusil vynutit kódem a lék se ukázal být
  horší než nemoc.
- **Je to vyhraněné.** PHP 8.x, konvence Laravelu, Pest místo PHPUnit tříd, PHPStan na nejvyšší
  úrovni, kterou pravidla projektu dovolí, DTO místo asociativních polí. Kdo s tím nesouhlasí, bude
  s pravidly bojovat, ne je používat.
- **Nenahrazuje review.** Nahrazuje **první** review — tu, která najde metodu se šesti parametry.
  Jestli tu funkci mělo cenu vůbec stavět, musí pořád rozhodnout člověk.

## Vyzkoušet

Repozitář je na
[pekral/ai-olympus](https://github.com/pekral/ai-olympus).
Pravidla a skilly mají cenu i samy, když nechcete spustit jediného agenta — polovina z nich není
vázaná na PHP vůbec.

Když vám to ušetří jedno kolečko review, hvězda pomůže ostatním ho najít.

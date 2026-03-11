# Šablony promptů pro LLM komunikaci

Všechny soubory v této složce jsou prostý text načítaný třídou `MealGenerator` (`src/MealGenerator.php`).
Proměnné ve formátu `{NAZEV}` jsou nahrazeny dynamickými hodnotami před odesláním na API.

Editace šablon nevyžaduje změnu PHP kódu — stačí upravit příslušný `.txt` soubor.

---

## `system.txt`

**Účel:** Systémový prompt (role: system). Definuje roli asistenta, jazyk, styl výstupu
a závazné pravidlo: odpověď musí být výhradně validní JSON bez obalujícího textu.

Posílá se jako první zpráva (`role: system`) při každém volání API.

**Kdy měnit:** Při změně stravovací filosofie, formátu výstupu nebo přidání nových pravidel.

---

## `meal_plan_generate.txt`

**Účel:** Hlavní uživatelský prompt (`role: user`) pro generování kompletního 7denního jídelníčku.
Obsahuje profil uživatele, volitelný blok historických preferencí a definici očekávaného JSON výstupu.

**Proměnné:**

| Proměnná          | Popis                                                              |
|-------------------|--------------------------------------------------------------------|
| `{USER_NAME}`     | Křestní jméno uživatele                                            |
| `{GENDER}`        | Pohlaví (muž / žena / jiné)                                        |
| `{AGE}`           | Věk v letech                                                       |
| `{BODY_TYPE}`     | Typ postavy z profilu (štíhlá / sportovní / průměrná / nadváha)    |
| `{DIETARY_NOTES}` | Stravovací omezení a poznámky z profilu uživatele                  |
| `{WEEK_NUMBER}`   | ISO číslo týdne (1–53)                                             |
| `{YEAR}`          | Rok                                                                |
| `{HISTORY_BLOCK}` | Obsah `meal_plan_history.txt` s dosazenými hodnotami, nebo prázdný řetězec pokud není dostatek dat (< 5 záznamů v `meal_history`) |
| `{JSON_SCHEMA}`   | Inline JSON schéma výstupního formátu (generuje `MealGenerator::getJsonSchema()`) |

---

## `meal_plan_history.txt`

**Účel:** Volitelný blok vkládaný za `{HISTORY_BLOCK}` v `meal_plan_generate.txt`.
Informuje model o preferencích uživatele odvozených ze statistik v tabulce `meal_history`.

Aktivuje se pouze pokud uživatel má celkem ≥ 5 položek ve výsledku:
- `liked` (times_eaten / times_offered ≥ 0.6, min. 3 nabídnutí)
- `disliked` (times_eaten / times_offered ≤ 0.2, min. 3 nabídnutí)

**Proměnné:**

| Proměnná          | Popis                                                  |
|-------------------|--------------------------------------------------------|
| `{LIKED_MEALS}`   | Čárkami oddělený seznam oblíbených jídel               |
| `{DISLIKED_MEALS}`| Čárkami oddělený seznam odmítaných jídel               |

---

## Formát ingrediencí v JSON odpovědi

Model vrací ingredience jako pole objektů:
```json
[
  {"name": "ovesné vločky", "quantity": 80, "unit": "g"},
  {"name": "mléko",          "quantity": 200, "unit": "ml"},
  {"name": "med",            "quantity": 1,   "unit": "lžíce"}
]
```

Tento formát je zpracováván třídou `ShoppingList::aggregateIngredients()`, která
agreguje ingredience z jídelníčku do nákupního seznamu s množstvími a jednotkami.

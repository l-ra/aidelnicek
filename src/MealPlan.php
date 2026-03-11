<?php

namespace Aidelnicek;

use PDO;

class MealPlan
{
    private const MEAL_TYPE_ORDER = ['breakfast', 'snack_am', 'lunch', 'snack_pm', 'dinner'];

    private const MEAL_TYPE_LABELS = [
        'breakfast' => 'Snídaně',
        'snack_am'  => 'Dopolední svačina',
        'lunch'     => 'Oběd',
        'snack_pm'  => 'Odpolední svačina',
        'dinner'    => 'Večeře',
    ];

    private const DAY_LABELS = [
        1 => 'Pondělí',
        2 => 'Úterý',
        3 => 'Středa',
        4 => 'Čtvrtek',
        5 => 'Pátek',
        6 => 'Sobota',
        7 => 'Neděle',
    ];

    private const DAY_SHORT_LABELS = [
        1 => 'Po',
        2 => 'Út',
        3 => 'St',
        4 => 'Čt',
        5 => 'Pá',
        6 => 'So',
        7 => 'Ne',
    ];

    // Demo meals indexed by meal_type; each entry is [alt1, alt2]
    // Format: [name, description, ingredients_json]
    private const DEMO_MEALS = [
        'breakfast' => [
            [
                ['Ovesná kaše s ovocem', 'Teplá kaše s banánem a borůvkami', '["ovesné vločky","mléko","banán","borůvky","med"]'],
                ['Řecký jogurt s granolou', 'Krémový jogurt s křupavou granolou a jahodami', '["řecký jogurt","granola","jahody","med"]'],
            ],
            [
                ['Celozrnný toast s avokádem', 'Toast s rozmačkaným avokádem a vajíčkem', '["celozrnný chléb","avokádo","vejce","citron","sůl"]'],
                ['Tvarohový krém s ovocem', 'Lehký tvaroh s sezonním ovocem', '["tvaroh","broskev","maliny","vanilkový cukr"]'],
            ],
            [
                ['Vajíčka na hnilobě', 'Míchaná vajíčka na másle se šunkou', '["vejce","šunka","máslo","sůl","pepř"]'],
                ['Smoothie bowl', 'Hustý ovocný smoothie s toppingy', '["banán","mango","jahody","kokosové mléko","semínka"]'],
            ],
            [
                ['Francouzský toast', 'Vajíčkový toast se skořicí a javorovým sirupem', '["toast","vejce","mléko","skořice","javorový sirup"]'],
                ['Müsli s mlékem', 'Cereálie s čerstvým ovocem a mlékem', '["müsli","mléko","banán","ořechy"]'],
            ],
            [
                ['Palačinky s tvarohem', 'Tenké palačinky plněné ochuceným tvarohem', '["mouka","vejce","mléko","tvaroh","vanilka","cukr"]'],
                ['Pečivo s máslem a medem', 'Čerstvé pečivo s domácím máslem', '["rohlík","máslo","med","šunka"]'],
            ],
            [
                ['Vejce benedikt', 'Ztracená vejce na toastu s holandskou omáčkou', '["vejce","toast","šunka","máslo","citron"]'],
                ['Ovocný salát s jogurtem', 'Mix sezonního ovoce s bílým jogurtem', '["jahody","kiwi","pomeranč","bílý jogurt","máta"]'],
            ],
            [
                ['Krupicová kaše', 'Jemná krupicová kaše s ovocem', '["krupice","mléko","máslo","cukr","jahody"]'],
                ['Celozrnný wrap se zeleninou', 'Wrap s tvarohem, okurkou a paprikou', '["celozrnný wrap","tvaroh","okurka","paprika","ledový salát"]'],
            ],
        ],
        'snack_am' => [
            [
                ['Jablko s mandlovým máslem', 'Nakrájené jablko s mandlovým máslem', '["jablko","mandlové máslo"]'],
                ['Banán a hrst ořechů', 'Rychlá svačina plná energie', '["banán","vlašské ořechy","mandle"]'],
            ],
            [
                ['Mrkvové tyčinky s hummusem', 'Syrová mrkev s cizrnovým hummusem', '["mrkev","hummus","citron"]'],
                ['Rýžový chlebíček s tvarohem', 'Lehká svačina s nízkým obsahem tuku', '["rýžový chlebíček","tvaroh","rajče"]'],
            ],
            [
                ['Tvarohový dezert', 'Tvaroh s medem a ořechy', '["tvaroh","med","vlašské ořechy","skořice"]'],
                ['Ovocný jogurt', 'Jogurt s čerstvým sezonním ovocem', '["jogurt","borůvky","maliny"]'],
            ],
            [
                ['Celozrnné sušenky', 'Křupavé sušenky s ovesem a ovocem', '["ovesné sušenky","rozinky","ořechy"]'],
                ['Smoothie', 'Ovocné smoothie s proteinem', '["banán","jahody","bílý jogurt","mléko"]'],
            ],
            [
                ['Pomeranč', 'Čerstvý pomeranč plný vitaminu C', '["pomeranč"]'],
                ['Tyčinka z granoly', 'Domácí energetická tyčinka', '["ovesné vločky","med","slunečnicová semínka","rozinky"]'],
            ],
            [
                ['Nakrájená paprika', 'Syrová paprika — sladká a křupavá', '["červená paprika","okurka"]'],
                ['Žitný chlebíček se šunkou', 'Lehká slaná svačina', '["žitný chlebíček","šunka","hořčice"]'],
            ],
            [
                ['Borůvky s tvarohem', 'Antiox svačina plná vitamínů', '["borůvky","tvaroh","med"]'],
                ['Ořechová směs', 'Hrst smíšených ořechů a sušeného ovoce', '["mandle","kešu","rozinky","brusinky"]'],
            ],
        ],
        'lunch' => [
            [
                ['Kuřecí prsa s rýží', 'Grilovaná kuřecí prsa s dušenou rýží a brokolicí', '["kuřecí prsa","rýže","brokolice","česnek","olivový olej"]'],
                ['Čočkový vývar', 'Výživná čočková polévka s kořenovou zeleninou', '["červená čočka","mrkev","celer","cibule","kmín","rajčata"]'],
            ],
            [
                ['Svíčková na smetaně', 'Tradiční česká svíčková s houskovým knedlíkem', '["hovězí svíčková","smetana","zelenina","houskový knedlík","brusinky"]'],
                ['Zeleninové curry', 'Indické curry se zeleninou a rýží', '["cizrna","špenát","rajčata","rýže","curry koření"]'],
            ],
            [
                ['Losos s bramborami', 'Pečený losos s vařenými brambory a salátem', '["losos","brambory","citron","kapary","ledový salát"]'],
                ['Špagety bolognese', 'Těstoviny s masovou omáčkou', '["špagety","mleté maso","rajčata","cibule","česnek","bazalka"]'],
            ],
            [
                ['Kuřecí polévka', 'Domácí vývar s kuřecím masem a nudlemi', '["kuřecí maso","mrkev","celer","petržel","nudle"]'],
                ['Tofu stir-fry', 'Restované tofu se zeleninou a sójovou omáčkou', '["tofu","brokolice","mrkev","sójová omáčka","zázvor","rýže"]'],
            ],
            [
                ['Guláš s knedlíkem', 'Hovězí guláš s paprikou a houškovým knedlíkem', '["hovězí maso","cibule","paprika","rajčatový protlak","houskový knedlík"]'],
                ['Zapečené těstoviny', 'Těstoviny zapečené s mozzarellou a zeleninou', '["penne","mozzarella","rajčata","špenát","bazalka"]'],
            ],
            [
                ['Rybí filé s quinoou', 'Pečené rybí filé s quinoou a pečenou zeleninou', '["treska","quinoa","cuketa","paprika","citron"]'],
                ['Hovězí burger', 'Domácí burger s čerstvou zeleninou', '["hovězí mleté maso","bulka","ledový salát","rajče","cibule","hořčice"]'],
            ],
            [
                ['Kuřecí kebab', 'Grilovaný kuřecí kebab s pita chlebem', '["kuřecí maso","pita chléb","tzatziki","rajče","okurka","červená cibule"]'],
                ['Pohankové rizoto', 'Krémové rizoto z pohanky se zeleninou', '["pohanka","houbový vývar","žampiony","cibule","parmezán"]'],
            ],
        ],
        'snack_pm' => [
            [
                ['Jablko s ořechy', 'Lehká sladká svačina', '["jablko","vlašské ořechy"]'],
                ['Tvaroh s ovocem', 'Tvaroh s meruňkami nebo broskvemi', '["tvaroh","meruňky","med"]'],
            ],
            [
                ['Žitný chléb s avokádem', 'Celozrnný chléb s avokádem a rajčetem', '["žitný chléb","avokádo","rajče","sůl"]'],
                ['Proteinový jogurt', 'Bílý jogurt s proteinem a ovocem', '["řecký jogurt","borůvky","lněná semínka"]'],
            ],
            [
                ['Nakrájená zelenina', 'Paprika, okurka a celer s dipy', '["paprika","okurka","celer","hummus"]'],
                ['Celozrnný toast s arašídovým máslem', 'Energetická svačina', '["celozrnný toast","arašídové máslo","banán"]'],
            ],
            [
                ['Ovocný salát', 'Čerstvý mix ovoce', '["jahody","kiwi","hrozny","máta"]'],
                ['Sýr s celozrnným pečivem', 'Slaná svačina s nízkotučným sýrem', '["celozrnný rohlík","eidam","rajče"]'],
            ],
            [
                ['Müsli tyčinka', 'Domácí cereální tyčinka', '["ovesné vločky","med","brusinky","dýňová semínka"]'],
                ['Kefír s ovocem', 'Probiotiký nápoj s ovocem', '["kefír","jahody","vanilka"]'],
            ],
            [
                ['Datlové kuličky', 'Přírodní sladkost z datlí a ořechů', '["datle","mandle","kakao","kokos"]'],
                ['Žitný chlebíček s lososem', 'Omega-3 svačina', '["žitný chlebíček","uzený losos","tvaroh","kapary"]'],
            ],
            [
                ['Hrozny a sýr', 'Klasická kombinace sladkého a slaného', '["hrozny","brie","celozrnné krekry"]'],
                ['Proteinové smoothie', 'Výživné smoothie po sportu', '["banán","arašídové máslo","mléko","skořice"]'],
            ],
        ],
        'dinner' => [
            [
                ['Zapečené kuře s brambory', 'Šťavnaté kuřecí stehno s pečenými bramborami', '["kuřecí stehna","brambory","česnek","rozmarýn","olivový olej"]'],
                ['Zeleninová polévka', 'Lehká krémová polévka z kořenové zeleniny', '["mrkev","petržel","celer","brambory","smetana"]'],
            ],
            [
                ['Rybí filé s brokolicí', 'Lehká večeře s grilovanou rybou', '["treska","brokolice","citron","olivový olej","česnek"]'],
                ['Omeletka se zeleninou', 'Vaječná omeletka se špenátem a sýrem', '["vejce","špenát","feta","rajče","olivový olej"]'],
            ],
            [
                ['Pečená krkovička', 'Vepřová krkovička s dušenou zeleninou', '["vepřová krkovička","zelí","mrkev","kmín","brambory"]'],
                ['Cizrnový salát', 'Výživný salát s cizrnou a feta sýrem', '["cizrna","rajčata","okurka","feta","olivový olej","citron"]'],
            ],
            [
                ['Kuřecí quesadilla', 'Tortilly plněné kuřetem a sýrem', '["tortilla","kuřecí prsa","cheddar","paprika","salsa"]'],
                ['Polévka minestrone', 'Italská zeleninová polévka s těstovinami', '["rajčata","cuketa","fazole","těstoviny","bazalka"]'],
            ],
            [
                ['Grilovaný losos', 'Losos se špenátem a citronovou omáčkou', '["losos","špenát","smetana","citron","kapary"]'],
                ['Čočka na kyselo', 'Tradiční české jídlo z červené čočky', '["čočka","cibule","česnek","ocet","kmín","vejce"]'],
            ],
            [
                ['Kuřecí polévka s nudlemi', 'Hřejivá polévka na konec týdne', '["kuřecí maso","mrkev","celer","petržel","nudle"]'],
                ['Zapečená cuketa', 'Cuketa plněná mletým masem a sýrem', '["cuketa","mleté maso","rajčata","mozzarella","bazalka"]'],
            ],
            [
                ['Svíčková (odlehčená)', 'Hovězí se smetanovou omáčkou a jasmínovou rýží', '["hovězí svíčková","smetana","mrkev","petržel","rýže"]'],
                ['Zeleninové frittata', 'Italská zapečená omeletka se zeleninou', '["vejce","cuketa","rajčata","feta","olivový olej","bazalka"]'],
            ],
        ],
    ];

    public static function getMealTypeLabel(string $type): string
    {
        return self::MEAL_TYPE_LABELS[$type] ?? $type;
    }

    public static function getMealTypeOrder(): array
    {
        return self::MEAL_TYPE_ORDER;
    }

    public static function getDayLabel(int $day): string
    {
        return self::DAY_LABELS[$day] ?? (string) $day;
    }

    public static function getDayShortLabel(int $day): string
    {
        return self::DAY_SHORT_LABELS[$day] ?? (string) $day;
    }

    public static function getOrCreateCurrentWeek(): array
    {
        $weekNumber = (int) date('W');
        $year       = (int) date('Y');

        $db   = Database::get();
        $stmt = $db->prepare('SELECT * FROM weeks WHERE week_number = ? AND year = ?');
        $stmt->execute([$weekNumber, $year]);
        $row = $stmt->fetch();

        if ($row !== false) {
            return $row;
        }

        $db->prepare('INSERT INTO weeks (week_number, year) VALUES (?, ?)')->execute([$weekNumber, $year]);
        $id = (int) $db->lastInsertId();

        return ['id' => $id, 'week_number' => $weekNumber, 'year' => $year, 'generated_at' => null];
    }

    public static function getWeekById(int $weekId): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM weeks WHERE id = ?');
        $stmt->execute([$weekId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public static function getDayPlan(int $userId, int $weekId, int $dayOfWeek): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM meal_plans
             WHERE user_id = ? AND week_id = ? AND day_of_week = ?
             ORDER BY alternative ASC'
        );
        $stmt->execute([$userId, $weekId, $dayOfWeek]);
        $rows = $stmt->fetchAll();

        $plan = [];
        foreach (self::MEAL_TYPE_ORDER as $type) {
            $plan[$type] = ['alt1' => null, 'alt2' => null];
        }

        foreach ($rows as $row) {
            $key = 'alt' . $row['alternative'];
            if (isset($plan[$row['meal_type']])) {
                $plan[$row['meal_type']][$key] = $row;
            }
        }

        return $plan;
    }

    public static function getWeekPlan(int $userId, int $weekId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM meal_plans
             WHERE user_id = ? AND week_id = ?
             ORDER BY day_of_week ASC, alternative ASC'
        );
        $stmt->execute([$userId, $weekId]);
        $rows = $stmt->fetchAll();

        $plan = [];
        for ($d = 1; $d <= 7; $d++) {
            $plan[$d] = [];
            foreach (self::MEAL_TYPE_ORDER as $type) {
                $plan[$d][$type] = ['alt1' => null, 'alt2' => null];
            }
        }

        foreach ($rows as $row) {
            $d   = (int) $row['day_of_week'];
            $key = 'alt' . $row['alternative'];
            if (isset($plan[$d][$row['meal_type']])) {
                $plan[$d][$row['meal_type']][$key] = $row;
            }
        }

        return $plan;
    }

    public static function chooseAlternative(int $userId, int $planId): bool
    {
        $db = Database::get();

        $stmt = $db->prepare(
            'SELECT week_id, day_of_week, meal_type FROM meal_plans WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$planId, $userId]);
        $plan = $stmt->fetch();

        if ($plan === false) {
            return false;
        }

        $db->beginTransaction();
        try {
            $db->prepare(
                'UPDATE meal_plans SET is_chosen = 0
                 WHERE user_id = ? AND week_id = ? AND day_of_week = ? AND meal_type = ?'
            )->execute([$userId, $plan['week_id'], $plan['day_of_week'], $plan['meal_type']]);

            $db->prepare(
                'UPDATE meal_plans SET is_chosen = 1 WHERE id = ? AND user_id = ?'
            )->execute([$planId, $userId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return false;
        }

        return true;
    }

    public static function toggleEaten(int $userId, int $planId): bool
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT is_eaten FROM meal_plans WHERE id = ? AND user_id = ?');
        $stmt->execute([$planId, $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return false;
        }

        $newValue = $row['is_eaten'] ? 0 : 1;
        $db->prepare('UPDATE meal_plans SET is_eaten = ? WHERE id = ? AND user_id = ?')
           ->execute([$newValue, $planId, $userId]);

        return true;
    }

    public static function isEaten(int $userId, int $planId): ?bool
    {
        $stmt = Database::get()->prepare('SELECT is_eaten FROM meal_plans WHERE id = ? AND user_id = ?');
        $stmt->execute([$planId, $userId]);
        $row = $stmt->fetch();
        return $row !== false ? (bool) $row['is_eaten'] : null;
    }

    public static function hasPlansForWeek(int $userId, int $weekId): bool
    {
        $stmt = Database::get()->prepare(
            'SELECT 1 FROM meal_plans WHERE user_id = ? AND week_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $weekId]);
        return $stmt->fetch() !== false;
    }

    public static function seedDemoWeek(int $userId, int $weekId): void
    {
        if (self::hasPlansForWeek($userId, $weekId)) {
            return;
        }

        $db   = Database::get();
        $stmt = $db->prepare(
            'INSERT OR IGNORE INTO meal_plans
                (user_id, week_id, day_of_week, meal_type, alternative, meal_name, description, ingredients)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        for ($day = 1; $day <= 7; $day++) {
            foreach (self::MEAL_TYPE_ORDER as $type) {
                // demo meals rotate using the day index (0-based) to get variety
                $dayIndex  = ($day - 1) % count(self::DEMO_MEALS[$type]);
                $pair      = self::DEMO_MEALS[$type][$dayIndex];

                foreach ([1, 2] as $alt) {
                    [$name, $desc, $ingredients] = $pair[$alt - 1];
                    $stmt->execute([$userId, $weekId, $day, $type, $alt, $name, $desc, $ingredients]);
                    MealHistory::recordOffer($userId, $name);
                }
            }
        }
    }
}

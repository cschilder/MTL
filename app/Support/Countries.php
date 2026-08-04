<?php

declare(strict_types=1);

namespace MTL\Support;

use MTL\Core\Translator;

defined('MTL_APP') || exit;

/**
 * Countries, by their ISO 3166-1 alpha-2 codes.
 *
 * The database stores the two-letter code — compact, language-neutral, and
 * what every geocoder speaks — but a person should never have to read "IS"
 * and know it means Iceland. Names come from PHP's intl extension in the
 * site's language; without intl the code itself is shown, which is exactly
 * the behaviour this class exists to improve on.
 */
final class Countries
{
    /**
     * Every officially assigned alpha-2 code. A static list rather than a
     * lookup through intl's resource bundles: the set changes about once a
     * decade, and shared hosts do not always ship the bundles to enumerate.
     */
    private const CODES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';

    /** @var array<string,array<string,string>> per-locale cache of code => name */
    private static array $lists = [];

    /**
     * The display name for one code, in the site's language.
     *
     * Unknown or empty codes come back unchanged: showing "XX" for a code
     * nothing can resolve is more honest than hiding it.
     */
    public static function name(string $code, ?string $locale = null): string
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return '';
        }

        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $code, $locale ?? Translator::locale());

            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }

    /**
     * Every country as code => localized name, sorted by name, for a select.
     *
     * @return array<string,string>
     */
    public static function all(?string $locale = null): array
    {
        $locale ??= Translator::locale();

        if (isset(self::$lists[$locale])) {
            return self::$lists[$locale];
        }

        $list = [];

        foreach (explode(' ', self::CODES) as $code) {
            $list[$code] = self::name($code, $locale);
        }

        $collator = class_exists(\Collator::class) ? new \Collator($locale) : null;

        uasort($list, static fn (string $a, string $b): int => $collator !== null
            ? (int) $collator->compare($a, $b)
            : strcasecmp($a, $b));

        return self::$lists[$locale] = $list;
    }
}

<?php

/**
 * Checks the geographic reference data files (see README.md in this directory).
 *
 *   php database/data/geography/check.php              validate the real data files
 *   php database/data/geography/check.php --strict     warnings count as errors
 *   php database/data/geography/check.php --self-test  prove that every check catches its known problem
 *
 * Reads files only: no database, no Laravel boot, nothing is written. Messages are ASCII.
 */

require __DIR__.'/../../../vendor/autoload.php';

use Illuminate\Support\Str;

const GEO_KINDS = ['quartier', 'village', 'localite', 'non_precise'];

const GEO_LIST_STATUSES = ['a_verifier', 'partiel', 'complet'];

const GEO_VERIFICATIONS = ['a_verifier', 'verifie'];

const GEO_SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

const GEO_DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

const GEO_MAX_LENGTH = 120;

/**
 * @return list<string>
 */
function geo_expected_communes(): array
{
    $slugs = [];

    foreach (range(1, 7) as $number) {
        $slugs[] = "golfe-{$number}";
    }

    foreach (range(1, 6) as $number) {
        $slugs[] = "agoe-nyive-{$number}";
    }

    return $slugs;
}

/**
 * Comparison key: lowercase, no accents, no spaces, hyphens, apostrophes or dots. Never displayed.
 */
function geo_key(string $text): string
{
    return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($text))) ?? '';
}

/**
 * @param  list<string>  $messages
 */
function geo_contains(array $messages, string $fragment): bool
{
    foreach ($messages as $message) {
        if (str_contains($message, $fragment)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, mixed>
 */
function geo_load(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("fichier introuvable : {$path}");
    }

    $content = file_get_contents($path);

    if ($content === false) {
        throw new RuntimeException("fichier illisible : {$path}");
    }

    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        throw new RuntimeException("BOM UTF-8 interdit : {$path}");
    }

    if (str_contains($content, "\u{00C3}")) {
        throw new RuntimeException("caracteres corrompus (encodage) : {$path}");
    }

    $data = json_decode($content, true);

    if (! is_array($data)) {
        throw new RuntimeException("JSON invalide : {$path} (".json_last_error_msg().')');
    }

    return $data;
}

/**
 * @param  array<string, mixed>  $sources  content of sources.json
 * @param  array<string, mixed>  $communes  content of communes.json
 * @param  array<string, array<string, mixed>>  $files  commune slug => content of its neighborhoods file
 * @return array{errors: list<string>, warnings: list<string>, stats: array<string, array<string, mixed>>}
 */
function geo_validate(array $sources, array $communes, array $files): array
{
    $errors = [];
    $warnings = [];
    $stats = [];
    $tiers = [];
    $located = [];

    foreach ((array) ($sources['sources'] ?? []) as $index => $source) {
        $id = is_array($source) ? ($source['id'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            $errors[] = 'sources.json : source n'.($index + 1).' sans identifiant';

            continue;
        }

        if (isset($tiers[$id])) {
            $errors[] = "sources.json : identifiant en double : {$id}";

            continue;
        }

        $tier = $source['tier'] ?? null;

        if (! is_int($tier) || $tier < 1 || $tier > 6) {
            $errors[] = "sources.json : {$id} : tier invalide (entier de 1 a 6)";
            $tier = 6;
        }

        $tiers[$id] = $tier;
        $located[$id] = ($source['located'] ?? false) === true;
    }

    $listed = [];

    foreach ((array) ($communes['communes'] ?? []) as $index => $commune) {
        $slug = is_array($commune) ? ($commune['slug'] ?? null) : null;

        if (! is_string($slug) || preg_match(GEO_SLUG_PATTERN, $slug) !== 1) {
            $errors[] = 'communes.json : commune n'.($index + 1).' : slug invalide';

            continue;
        }

        if (isset($listed[$slug])) {
            $errors[] = "communes.json : commune en double : {$slug}";

            continue;
        }

        foreach (['name', 'prefecture'] as $field) {
            if (! is_string($commune[$field] ?? null) || trim($commune[$field]) === '') {
                $errors[] = "communes.json : {$slug} : champ '{$field}' manquant";
            }
        }

        foreach ((array) ($commune['sources'] ?? []) as $sourceId) {
            if (! is_string($sourceId) || ! isset($tiers[$sourceId])) {
                $errors[] = "communes.json : {$slug} : source inconnue : ".(is_string($sourceId) ? $sourceId : '?');
            }
        }

        $listed[$slug] = true;
    }

    $listedSlugs = array_map('strval', array_keys($listed));

    foreach (array_diff(geo_expected_communes(), $listedSlugs) as $missing) {
        $errors[] = "communes.json : commune attendue absente : {$missing}";
    }

    foreach (array_diff($listedSlugs, geo_expected_communes()) as $extra) {
        $errors[] = "communes.json : commune inattendue : {$extra}";
    }

    foreach (array_diff(array_map('strval', array_keys($files)), $listedSlugs) as $stray) {
        $errors[] = "fichier de quartiers inattendu : {$stray}";
    }

    $entries = [];

    foreach ($listedSlugs as $communeSlug) {
        if (! array_key_exists($communeSlug, $files)) {
            $errors[] = "{$communeSlug} : fichier de quartiers absent";

            continue;
        }

        $file = $files[$communeSlug];

        if (($file['commune'] ?? null) !== $communeSlug) {
            $errors[] = "{$communeSlug} : le champ commune du fichier ne correspond pas au nom du fichier";
        }

        $status = $file['list_status'] ?? null;

        if (! in_array($status, GEO_LIST_STATUSES, true)) {
            $errors[] = "{$communeSlug} : list_status invalide (a_verifier, partiel ou complet)";
            $status = 'a_verifier';
        }

        $declared = $file['declared_count'] ?? null;

        if ($declared !== null && (! is_int($declared) || $declared < 0)) {
            $errors[] = "{$communeSlug} : declared_count doit etre vide ou un entier positif";
            $declared = null;
        }

        $list = $file['neighborhoods'] ?? null;

        if (! is_array($list) || ! array_is_list($list)) {
            $errors[] = "{$communeSlug} : neighborhoods doit etre une liste";
            $list = [];
        }

        $verified = 0;

        foreach ($list as $position => $entry) {
            $label = "{$communeSlug} n".($position + 1);

            if (! is_array($entry)) {
                $errors[] = "{$label} : entree invalide";

                continue;
            }

            $name = $entry['name'] ?? null;

            if (! is_string($name) || trim($name) === '' || mb_strlen($name) > GEO_MAX_LENGTH) {
                $errors[] = "{$label} : nom invalide (texte de 1 a 120 caracteres)";

                continue;
            }

            $label = "{$communeSlug} / {$name}";
            $key = geo_key($name);

            if ($key === '') {
                $errors[] = "{$label} : le nom ne contient ni lettre ni chiffre";

                continue;
            }

            if (($entry['commune'] ?? null) !== $communeSlug) {
                $errors[] = "{$label} : le champ commune doit valoir {$communeSlug}";
            }

            $slug = $entry['slug'] ?? null;

            if (! is_string($slug) || preg_match(GEO_SLUG_PATTERN, $slug) !== 1 || strlen($slug) > GEO_MAX_LENGTH) {
                $errors[] = "{$label} : slug invalide (minuscules, chiffres et tirets, 120 caracteres maximum)";
                $slug = null;
            } elseif ($slug !== Str::slug($name) && trim((string) ($entry['slug_reason'] ?? '')) === '') {
                $errors[] = "{$label} : le slug '{$slug}' differe de '".Str::slug($name)."' : le justifier dans slug_reason";
            }

            $kind = $entry['kind'] ?? null;

            if (! in_array($kind, GEO_KINDS, true)) {
                $errors[] = "{$label} : kind invalide (quartier, village, localite ou non_precise)";
            }

            $entrySources = $entry['sources'] ?? [];

            if (! is_array($entrySources) || ! array_is_list($entrySources) || array_filter($entrySources, fn ($id) => ! is_string($id)) !== []) {
                $errors[] = "{$label} : sources doit etre une liste d'identifiants";
                $entrySources = [];
            }

            if ($entrySources === []) {
                $errors[] = "{$label} : au moins une source est necessaire";
            }

            foreach ($entrySources as $sourceId) {
                if (! isset($tiers[$sourceId])) {
                    $errors[] = "{$label} : source inconnue : {$sourceId}";
                }
            }

            $verification = $entry['verification'] ?? null;

            if (! in_array($verification, GEO_VERIFICATIONS, true)) {
                $errors[] = "{$label} : verification invalide (a_verifier ou verifie)";
                $verification = 'a_verifier';
            }

            if ($verification === 'verifie') {
                $proof = array_values(array_unique(array_filter($entrySources, fn ($id) => ($located[$id] ?? false) === true)));

                if (count($proof) < 2) {
                    $errors[] = "{$label} : 'verifie' exige au moins 2 sources distinctes dont le document est en main (located)";
                } elseif (min(array_map(fn ($id) => $tiers[$id], $proof)) > 3) {
                    $errors[] = "{$label} : 'verifie' exige au moins une source de niveau 1 a 3";
                }

                if (trim((string) ($entry['verified_by'] ?? '')) === '' || preg_match(GEO_DATE_PATTERN, (string) ($entry['verified_on'] ?? '')) !== 1) {
                    $errors[] = "{$label} : 'verifie' exige verified_by et verified_on (AAAA-MM-JJ)";
                }

                $verified++;
            }

            $aliasKeys = [];
            $aliases = $entry['aliases'] ?? [];

            if (! is_array($aliases) || ! array_is_list($aliases)) {
                $errors[] = "{$label} : aliases doit etre une liste";
                $aliases = [];
            }

            foreach ($aliases as $alias) {
                if (! is_string($alias) || mb_strlen($alias) > GEO_MAX_LENGTH || geo_key($alias) === '') {
                    $errors[] = "{$label} : alias invalide";

                    continue;
                }

                $aliasKey = geo_key($alias);

                if ($aliasKey === $key || in_array($aliasKey, $aliasKeys, true)) {
                    $warnings[] = "{$label} : l'alias '{$alias}' est inutile (identique au nom ou a un autre alias une fois normalise)";

                    continue;
                }

                $aliasKeys[] = $aliasKey;
            }

            $distinct = $entry['distinct_from'] ?? [];

            if (! is_array($distinct) || ! array_is_list($distinct) || array_filter($distinct, fn ($s) => ! is_string($s)) !== []) {
                $errors[] = "{$label} : distinct_from doit etre une liste de slugs";
                $distinct = [];
            }

            $entries[] = [
                'commune' => $communeSlug,
                'name' => $name,
                'slug' => $slug,
                'key' => $key,
                'aliasKeys' => $aliasKeys,
                'distinct' => $distinct,
            ];
        }

        $count = count($list);

        if ($status === 'partiel' && $count === 0) {
            $errors[] = "{$communeSlug} : statut 'partiel' sans aucun quartier";
        }

        if ($status === 'complet') {
            if (trim((string) ($file['reviewed_by'] ?? '')) === '' || preg_match(GEO_DATE_PATTERN, (string) ($file['reviewed_on'] ?? '')) !== 1) {
                $errors[] = "{$communeSlug} : 'complet' exige reviewed_by et reviewed_on (AAAA-MM-JJ)";
            }

            if ($declared === null || $declared !== $count) {
                $errors[] = "{$communeSlug} : 'complet' exige un declared_count egal au nombre de quartiers ({$count})";
            }

            if ($verified !== $count) {
                $errors[] = "{$communeSlug} : 'complet' exige que tous les quartiers soient 'verifie'";
            }
        }

        $stats[$communeSlug] = [
            'status' => $status,
            'entries' => $count,
            'verified' => $verified,
            'declared' => $declared,
            'missing' => $declared === null ? null : max(0, $declared - $count),
        ];
    }

    $bySlug = [];

    foreach ($entries as $entry) {
        if ($entry['slug'] !== null) {
            $bySlug[$entry['slug']][] = $entry;
        }
    }

    foreach ($bySlug as $slug => $group) {
        if (count($group) > 1) {
            $where = array_map(fn (array $e) => "{$e['commune']} / {$e['name']}", $group);
            $errors[] = "collision de slug dans la ville : {$slug} (".implode(' ; ', $where).')';
        }
    }

    foreach ($entries as $entry) {
        foreach ($entry['distinct'] as $reference) {
            if (! isset($bySlug[$reference])) {
                $errors[] = "{$entry['commune']} / {$entry['name']} : distinct_from reference un slug inconnu : {$reference}";
            }
        }
    }

    $byCommuneKey = [];
    $byKey = [];

    foreach ($entries as $entry) {
        $byCommuneKey[$entry['commune'].'|'.$entry['key']][] = $entry['name'];
        $byKey[$entry['key']][$entry['commune']][] = $entry['name'];
    }

    foreach ($byCommuneKey as $identifier => $names) {
        if (count($names) > 1) {
            $errors[] = 'meme cle de comparaison dans '.explode('|', $identifier)[0].' : '.implode(' / ', $names).' (a fusionner en alias ou a distinguer)';
        }
    }

    foreach ($byKey as $communesWithKey) {
        if (count($communesWithKey) > 1) {
            $warnings[] = 'meme nom dans plusieurs communes ('.implode(', ', array_keys($communesWithKey)).') : verifier que ce sont des lieux distincts, avec des slugs differents';
        }
    }

    foreach ($entries as $index => $entry) {
        foreach ($entry['aliasKeys'] as $aliasKey) {
            foreach ($entries as $otherIndex => $other) {
                if ($index === $otherIndex || $other['key'] !== $aliasKey) {
                    continue;
                }

                $message = "alias '{$aliasKey}' de {$entry['commune']} / {$entry['name']} correspond au nom de {$other['commune']} / {$other['name']}";

                if ($entry['commune'] === $other['commune']) {
                    $errors[] = $message.' (meme commune)';
                } else {
                    $warnings[] = $message;
                }
            }
        }
    }

    $aliasOwners = [];

    foreach ($entries as $index => $entry) {
        foreach ($entry['aliasKeys'] as $aliasKey) {
            $aliasOwners[$aliasKey][$entry['commune']][] = $index;
        }
    }

    foreach ($aliasOwners as $aliasKey => $perCommune) {
        foreach ($perCommune as $communeSlug => $owners) {
            if (count($owners) > 1) {
                $errors[] = "alias '{$aliasKey}' partage entre plusieurs quartiers de {$communeSlug}";
            }
        }

        if (count($perCommune) > 1) {
            $warnings[] = "alias '{$aliasKey}' utilise dans plusieurs communes (".implode(', ', array_keys($perCommune)).')';
        }
    }

    $perCommune = [];

    foreach ($entries as $index => $entry) {
        $perCommune[$entry['commune']][] = $index;
    }

    foreach ($perCommune as $indexes) {
        foreach ($indexes as $position => $i) {
            foreach (array_slice($indexes, $position + 1) as $j) {
                $x = $entries[$i];
                $y = $entries[$j];

                if ($x['key'] === $y['key'] || min(strlen($x['key']), strlen($y['key'])) < 5 || levenshtein($x['key'], $y['key']) > 1) {
                    continue;
                }

                if (in_array($y['slug'], $x['distinct'], true) || in_array($x['slug'], $y['distinct'], true)) {
                    continue;
                }

                $warnings[] = "noms tres proches dans {$x['commune']} : '{$x['name']}' et '{$y['name']}' (a fusionner en alias, ou a declarer distincts avec distinct_from)";
            }
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings, 'stats' => $stats];
}

function geo_self_test(): int
{
    $communeSlugs = geo_expected_communes();

    $base = function () use ($communeSlugs): array {
        return [
            'sources' => ['sources' => [
                ['id' => 'src-t1', 'tier' => 2, 'located' => true],
                ['id' => 'src-t2', 'tier' => 3, 'located' => true],
                ['id' => 'src-t3', 'tier' => 5, 'located' => true],
                ['id' => 'src-t4', 'tier' => 5, 'located' => true],
                ['id' => 'src-t5', 'tier' => 1, 'located' => false],
            ]],
            'communes' => ['communes' => array_map(
                fn (string $slug) => ['slug' => $slug, 'name' => $slug, 'prefecture' => 'test', 'sources' => []],
                $communeSlugs
            )],
            'files' => array_combine($communeSlugs, array_map(
                fn (string $slug) => ['commune' => $slug, 'list_status' => 'a_verifier', 'declared_count' => null, 'neighborhoods' => []],
                $communeSlugs
            )),
        ];
    };

    $entry = fn (string $commune, string $name, array $extra = []): array => array_merge([
        'name' => $name,
        'commune' => $commune,
        'slug' => Str::slug($name),
        'kind' => 'non_precise',
        'aliases' => [],
        'sources' => ['src-t1'],
        'verification' => 'a_verifier',
        'distinct_from' => [],
    ], $extra);

    $add = function (array $data, string $commune, array $entryData) {
        $data['files'][$commune]['neighborhoods'][] = $entryData;

        return $data;
    };

    $proof = ['verification' => 'verifie', 'sources' => ['src-t1', 'src-t2'], 'verified_by' => 'relecteur', 'verified_on' => '2026-01-01'];

    // [label, data mutation, expected error fragment (null = no error), expected warning fragment, forbidden warning fragment]
    $cases = [
        ['jeu sain, aucun quartier', fn ($d) => $d, null, null, null],
        ['quartier simple valide', fn ($d) => $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Un')), null, null, null],
        ['collision de slug entre deux communes', fn ($d) => $add($add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Deux')), 'golfe-2', $entry('golfe-2', 'Zzz Test Deux')), 'collision de slug', null, null],
        ['meme cle dans une commune', fn ($d) => $add($add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Trois')), 'golfe-1', $entry('golfe-1', 'Zzz-Test Trois', ['slug' => 'zzz-test-trois-bis', 'slug_reason' => 'test'])), 'meme cle de comparaison', null, null],
        ['alias egal au nom d\'un voisin', fn ($d) => $add($add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Quatre')), 'golfe-1', $entry('golfe-1', 'Zzz Test Cinq', ['aliases' => ['Zzz Test Quatre']])), 'alias', null, null],
        ['source inconnue', fn ($d) => $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Six', ['sources' => ['src-inconnue']])), 'source inconnue', null, null],
        ['verifie avec une seule source', fn ($d) => $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Sept', array_merge($proof, ['sources' => ['src-t1']]))), '2 sources distinctes', null, null],
        ['verifie avec une source non localisee', fn ($d) => $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Huit', array_merge($proof, ['sources' => ['src-t1', 'src-t5']]))), '2 sources distinctes', null, null],
        ['verifie sans source de niveau 1 a 3', fn ($d) => $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Neuf', array_merge($proof, ['sources' => ['src-t3', 'src-t4']]))), 'niveau 1 a 3', null, null],
        ['verifie valide', fn ($d) => $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Dix', $proof)), null, null, null],
        ['complet avec un decompte different', function ($d) use ($add, $entry, $proof) {
            $d = $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Onze', $proof));
            $d['files']['golfe-1'] = array_merge($d['files']['golfe-1'], ['list_status' => 'complet', 'declared_count' => 2, 'reviewed_by' => 'relecteur', 'reviewed_on' => '2026-01-01']);

            return $d;
        }, 'declared_count', null, null],
        ['slug different du nom sans justification', fn ($d) => $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Douze', ['slug' => 'autre-slug'])), 'slug', null, null],
        ['mauvais champ commune', fn ($d) => $add($d, 'golfe-1', $entry('golfe-2', 'Zzz Test Treize')), 'commune', null, null],
        ['fichier de commune absent', function ($d) {
            unset($d['files']['golfe-3']);

            return $d;
        }, 'fichier de quartiers absent', null, null],
        ['quartier sans source', fn ($d) => $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Quatorze', ['sources' => []])), 'au moins une source', null, null],
        ['partiel sans quartier', function ($d) {
            $d['files']['golfe-1']['list_status'] = 'partiel';

            return $d;
        }, 'partiel', null, null],
        ['noms tres proches : avertissement', fn ($d) => $add($add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Quinze')), 'golfe-1', $entry('golfe-1', 'Zzz Test Quinzes')), null, 'tres proches', null],
        ['noms proches declares distincts', fn ($d) => $add($add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Seize', ['distinct_from' => ['zzz-test-seizes']])), 'golfe-1', $entry('golfe-1', 'Zzz Test Seizes')), null, null, 'tres proches'],
        ['meme nom dans deux communes, slugs distincts', fn ($d) => $add($add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Dixsept')), 'golfe-2', $entry('golfe-2', 'Zzz Test Dixsept', ['slug' => 'zzz-test-dixsept-golfe-2', 'slug_reason' => 'test'])), null, 'plusieurs communes', null],
        ['distinct_from vers un slug inconnu', fn ($d) => $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Dixhuit', ['distinct_from' => ['slug-inexistant']])), 'distinct_from', null, null],
        ['fichier inattendu', function ($d) {
            $d['files']['zzz'] = ['commune' => 'zzz', 'list_status' => 'a_verifier', 'neighborhoods' => []];

            return $d;
        }, 'inattendu', null, null],
        ['type invalide', fn ($d) => $add($d, 'golfe-1', $entry('golfe-1', 'Zzz Test Dixneuf', ['kind' => 'ville'])), 'kind', null, null],
        ['commune attendue absente de communes.json', function ($d) {
            array_pop($d['communes']['communes']);

            return $d;
        }, 'commune attendue absente', null, null],
    ];

    $failures = 0;

    foreach ($cases as [$label, $mutate, $errorFragment, $warningFragment, $forbiddenWarning]) {
        $data = $mutate($base());
        $result = geo_validate($data['sources'], $data['communes'], $data['files']);

        $ok = $errorFragment === null ? $result['errors'] === [] : geo_contains($result['errors'], $errorFragment);

        if ($ok && $warningFragment !== null) {
            $ok = geo_contains($result['warnings'], $warningFragment);
        }

        if ($ok && $forbiddenWarning !== null) {
            $ok = ! geo_contains($result['warnings'], $forbiddenWarning);
        }

        echo ($ok ? 'PASS' : 'FAIL').'  '.$label.PHP_EOL;

        if (! $ok) {
            $failures++;

            foreach (array_merge($result['errors'], $result['warnings']) as $message) {
                echo '        '.$message.PHP_EOL;
            }
        }
    }

    echo PHP_EOL.(count($cases) - $failures).' / '.count($cases).' controles verifies'.PHP_EOL;

    return $failures === 0 ? 0 : 1;
}

/**
 * @param  list<string>  $argv
 */
function geo_main(array $argv): int
{
    $arguments = array_slice($argv, 1);

    if (in_array('--self-test', $arguments, true)) {
        return geo_self_test();
    }

    $strict = in_array('--strict', $arguments, true);
    $base = __DIR__;

    try {
        $sources = geo_load($base.'/sources.json');
        $communes = geo_load($base.'/togo/lome/communes.json');
        $files = [];

        foreach (glob($base.'/togo/lome/neighborhoods/*.json') ?: [] as $path) {
            $files[basename($path, '.json')] = geo_load($path);
        }
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'ERREUR : '.$exception->getMessage().PHP_EOL);

        return 1;
    }

    $result = geo_validate($sources, $communes, $files);

    echo 'Referentiel geographique : Lome (TG)'.PHP_EOL.PHP_EOL;
    printf("%-14s %-11s %9s %9s %9s %9s\n", 'Commune', 'Statut', 'Quartiers', 'Verifies', 'Declares', 'Manquants');

    $entries = 0;
    $verified = 0;

    foreach ($result['stats'] as $slug => $stat) {
        printf("%-14s %-11s %9d %9d %9s %9s\n", $slug, $stat['status'], $stat['entries'], $stat['verified'], $stat['declared'] ?? '-', $stat['missing'] ?? '-');
        $entries += $stat['entries'];
        $verified += $stat['verified'];
    }

    echo PHP_EOL.count($result['stats']).' communes, '.$entries.' quartiers renseignes, '.$verified.' verifies'.PHP_EOL;

    foreach ($result['errors'] as $message) {
        echo 'ERREUR      '.$message.PHP_EOL;
    }

    foreach ($result['warnings'] as $message) {
        echo 'AVERTISSEMENT '.$message.PHP_EOL;
    }

    echo PHP_EOL.count($result['errors']).' erreur(s), '.count($result['warnings']).' avertissement(s)'.PHP_EOL;
    echo "RAPPEL : aucune liste n'est presumee exhaustive ; 'complet' n'est accepte que s'il est justifie.".PHP_EOL;

    return ($result['errors'] !== [] || ($strict && $result['warnings'] !== [])) ? 1 : 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(geo_main($argv));
}

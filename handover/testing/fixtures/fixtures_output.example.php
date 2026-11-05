<?php
/**
 * Produces the file `check.py fixtures` compares with showcases.json and gigs.json.
 * Copy it next to your code, fill in the three functions with calls into your
 * own glue, and run it with the path of this fixtures folder:
 *
 *     php fixtures_output.php /path/to/senseigigz-engine/handover/testing/fixtures > fixtures_output.json
 *     python check.py fixtures fixtures_output.json
 *
 * The functions get each fixture's `input` (team31-app's field names: map them to
 * yours) and must return what your real code produces, not a reimplementation.
 */

/**
 * G1. $input: headline (plain text), about (bio, HTML), years_experience (int|null),
 * services ([{title, description (HTML|null)}]), achievements ([HTML]).
 *
 * @return array The record you would store as showcase_embeddings.fields_json
 */
function yourShowcaseRecord(array $input): array
{
    // e.g. return MyApp\Engine\ProfileText::record($input['headline'], $input['about'], ...);
    throw new LogicException('Fill in yourShowcaseRecord()');
}

/**
 * G1. The text you send to embedding-service for $record, and its hash.
 *
 * @return array{0: string, 1: string} [text, sha256 hex]
 */
function yourTextAndHash(array $record): array
{
    throw new LogicException('Fill in yourTextAndHash()');
}

/**
 * G5. $input: title, summary (the gig's main description), additional_notes,
 * tags ([{category, specialisation, skills: [names]}], all real SkillsFuture
 * names). Create the gig with these tags in your staging database, or build the
 * request from them directly if your code allows it.
 *
 * @return array The /match request your code would send (any hire_id: it is replaced)
 */
function yourMatchRequest(array $input): array
{
    throw new LogicException('Fill in yourMatchRequest()');
}

$dir = rtrim($argv[1] ?? __DIR__, '/');
$out = ['showcases' => [], 'gigs' => []];
foreach (json_decode((string) file_get_contents("$dir/showcases.json"), true) as $case) {
    $record           = yourShowcaseRecord($case['input']);
    [$text, $hash]    = yourTextAndHash($record);
    $out['showcases'][] = ['id' => $case['id'], 'fields_json' => $record, 'provider_text' => $text, 'text_hash' => $hash];
}
foreach (json_decode((string) file_get_contents("$dir/gigs.json"), true) as $case) {
    $request            = yourMatchRequest($case['input']);
    $request['hire_id'] = $case['id'];
    $out['gigs'][]      = ['id' => $case['id'], 'match_request' => $request];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";

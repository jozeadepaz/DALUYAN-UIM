<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$seedPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'seed.json';
$people = json_decode((string) file_get_contents($seedPath), true);
if (!is_array($people)) {
    fwrite(STDERR, "The directory seed file is invalid.\n");
    exit(1);
}

$connection = daluyan_db();
$connection->beginTransaction();
try {
    $insertUser = $connection->prepare(
        'INSERT IGNORE INTO users (seed_key, name, email, role_key, position, directory_category, tag_color, icon, directory_visible, active)
         VALUES (:seed_key, :name, :email, :role, :position, :category, :tag_color, :icon, 1, 0)'
    );
    $insertAssignment = $connection->prepare(
        'INSERT IGNORE INTO user_committees (user_id, committee_key, is_primary)
         SELECT id, :committee_key, :is_primary FROM users WHERE email = :email'
    );

    foreach ($people as $person) {
        $category = (string) $person['category'];
        $role = $category === 'Executives' ? 'executive' : 'officer';
        $primaryCommittee = $category === 'Executives' ? 'Executives' : $category;
        $email = strtolower((string) $person['email']);
        $insertUser->execute([
            'seed_key' => (string) $person['id'],
            'name' => $person['name'],
            'email' => $email,
            'role' => $role,
            'position' => $person['role'],
            'category' => $category,
            'tag_color' => $person['tagColor'],
            'icon' => $person['icon']
        ]);
        $insertAssignment->execute(['committee_key' => $primaryCommittee, 'is_primary' => 1, 'email' => $email]);

        $secondaryCommittee = match ($email) {
            '24-73629@g.batstate-u.edu.ph', '25-70913@g.batstate-u.edu.ph', '24-74935@g.batstate-u.edu.ph' => 'CommFIMA',
            '23-74736@g.batstate-u.edu.ph' => 'CommCREL',
            default => null
        };
        if ($secondaryCommittee !== null && $secondaryCommittee !== $primaryCommittee) {
            $insertAssignment->execute(['committee_key' => $secondaryCommittee, 'is_primary' => 0, 'email' => $email]);
        }
    }

    $insertAdviser = $connection->prepare(
        "INSERT IGNORE INTO users (seed_key, name, email, role_key, position, directory_category, tag_color, icon, directory_visible, active)
         VALUES ('adviser-dyemnah-bentir', 'Dr. Dyemnah Inasiah P. Bentir', 'dyemnahinasiah.bentir@g.batstate-u.edu.ph', 'adviser', 'SSC Faculty Adviser', 'Adviser', 'brandAmber', 'fa-user-tie', 0, 0)"
    );
    $insertAdviser->execute();
    $insertAssignment->execute(['committee_key' => 'Adviser', 'is_primary' => 1, 'email' => 'dyemnahinasiah.bentir@g.batstate-u.edu.ph']);

    $connection->commit();
    fwrite(STDOUT, count($people) . " directory accounts and one adviser account are ready for individual password setup.\n");
} catch (Throwable $error) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    fwrite(STDERR, 'Seeding failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

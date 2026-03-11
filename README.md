Replace this file:
- bolzano/includes/functions.php

This fix adds backward-compatibility helpers so old pages stop crashing:
- migrateV42(PDO $pdo) -> safe no-op shim
- ensureDefaultAnonymousDonors(PDO $pdo)
- publicBaseUrl()

This resolves the fatal error on anonymous_collections.php even if that page still calls migrateV42().

<?php
// ONLY needed once, for migrate_old_pos.php (copies the OLD POS database into the HRMS database).
// Copy to db_config.php (git-ignored) with the OLD POS database login, run the migration, then delete this file.
// The POS itself does not use this file: it runs on the HRMS database (empmanagment/config.php).
return ['host' => 'localhost', 'name' => 'OLD_POS_DB_NAME', 'user' => 'OLD_POS_DB_USER', 'pass' => 'OLD_POS_DB_PASSWORD'];

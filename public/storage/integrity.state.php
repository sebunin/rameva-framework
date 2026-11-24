<?php
// Состояние последней проверки целостности system/ (Tripwire).
if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }
return array (
  'last_check' => 1795364264,
  'last_check_iso' => '2026-11-22T19:17:44+03:00',
  'last_status' => 'mismatch',
  'last_summary' => 'Integrity: расхождение system/ — изменено: 7 [Boot.php, Core/Installer.php, Core/Kernel.php, Security/Tripwire.php, Services/Cache.php, Services/ClientIp.php, Services/SessionService.php], удалено: 0, добавлено: 0, права: 0',
  'last_stray_check' => 1795561096,
  'last_stray_check_iso' => '2026-11-25T01:58:16+03:00',
  'last_stray_found' => 
  array (
  ),
  'last_stray_sensitive' => 
  array (
    0 => 'backup.sql',
  ),
);

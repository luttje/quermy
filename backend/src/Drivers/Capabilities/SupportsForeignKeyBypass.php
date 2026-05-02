<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

/** Marker: driver supports bypassing FK constraints during DROP TABLE / TRUNCATE TABLE. */
interface SupportsForeignKeyBypass {}

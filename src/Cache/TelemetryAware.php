<?php

namespace Utopia\Cache;

use Utopia\Telemetry\Adapter as Telemetry;

interface TelemetryAware
{
    public function setTelemetry(Telemetry $telemetry): void;
}

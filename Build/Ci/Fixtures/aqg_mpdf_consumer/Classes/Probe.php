<?php

namespace AqgTest\MpdfConsumer;

final class Probe
{
    public static function mpdfLoadable(): bool
    {
        return class_exists(\Mpdf\Mpdf::class);
    }
}

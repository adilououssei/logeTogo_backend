<?php

namespace App\Tests\Service;

use App\Service\TelechargementsPlayStore;
use PHPUnit\Framework\TestCase;

class TelechargementsPlayStoreTest extends TestCase
{
    private const CSV = "Date,Package Name,Daily Device Installs,Daily User Installs,Active Device Installs\n"
        ."2026-11-01,com.exemple,5,4,100\n2026-11-02,com.exemple,3,2,102\n";

    public function testLitUnCsvUtf8(): void
    {
        self::assertSame([6, 102, '2026-11-02'], TelechargementsPlayStore::lireCsv(self::CSV));
    }

    public function testLitUnCsvUtf16LeCommeGooglePlay(): void
    {
        $utf16 = "\xFF\xFE".mb_convert_encoding(self::CSV, 'UTF-16LE', 'UTF-8');
        self::assertSame([6, 102, '2026-11-02'], TelechargementsPlayStore::lireCsv($utf16));
    }

    public function testCsvVideOuInconnu(): void
    {
        self::assertSame([0, null, null], TelechargementsPlayStore::lireCsv("Date,Autre\n"));
        self::assertSame([0, null, null], TelechargementsPlayStore::lireCsv(''));
    }
}

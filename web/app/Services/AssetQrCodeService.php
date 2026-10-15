<?php

namespace App\Services;

use App\Models\InspectableAsset;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;

class AssetQrCodeService
{
    public function svg(InspectableAsset $asset): string
    {
        return (new Builder(
            writer: new SvgWriter,
            writerOptions: [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true],
            validateResult: false,
            data: route('assets.scan', $asset->qr_token),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 320,
            margin: 12,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build()->getString();
    }
}

<?php
namespace App\Service;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Validator\Validator\ValidatorInterface;
final class ExcelImportService
{
    public function __construct(private ValidatorInterface $validator) {}
    public function read(string $path): array
    {
        $spreadsheet=IOFactory::load($path);
        $sheet=$spreadsheet->getActiveSheet();
        return $sheet->toArray(null,true,true,true);
    }
}

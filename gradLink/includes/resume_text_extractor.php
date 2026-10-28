<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\IOFactory;

function extractTextFromTxt(string $filePath): string
{
    $content = @file_get_contents($filePath);
    return $content !== false ? trim($content) : '';
}

function extractTextFromPdf(string $filePath): string
{
    try {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);
        $text = $pdf->getText();

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    } catch (Throwable $e) {
        return '';
    }
}

function extractTextFromDocx(string $filePath): string
{
    try {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();

                if ($xml !== false) {
                    $text = strip_tags($xml);
                    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $text = preg_replace('/\s+/', ' ', $text);
                    return trim($text);
                }
            }
        }

        $phpWord = IOFactory::load($filePath);
        $allText = '';

        foreach ($phpWord->getSections() as $section) {
            $elements = $section->getElements();
            foreach ($elements as $element) {
                if (method_exists($element, 'getText')) {
                    $allText .= ' ' . $element->getText();
                }
            }
        }

        $allText = html_entity_decode($allText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $allText = preg_replace('/\s+/', ' ', $allText);

        return trim($allText);
    } catch (Throwable $e) {
        return '';
    }
}

function extractResumeText(string $filePath, string $extension): string
{
    $extension = strtolower($extension);

    return match ($extension) {
        'txt' => extractTextFromTxt($filePath),
        'pdf' => extractTextFromPdf($filePath),
        'docx' => extractTextFromDocx($filePath),
        default => ''
    };
}
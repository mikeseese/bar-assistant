<?php

declare(strict_types=1);

namespace Kami\Cocktail\Export;

use ZipArchive;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;
use Kami\Cocktail\ExportTypeEnum;
use Illuminate\Support\Facades\DB;
use Kami\Cocktail\Models\Cocktail;
use Illuminate\Support\Facades\File;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Exceptions\ExportFileNotCreatedException;

class Recipes
{
    public function process(int $barId, ?string $exportPath = null, ExportTypeEnum $exportType = ExportTypeEnum::YAML): string
    {
        $version = config('bar-assistant.version');
        $meta = [
            'version' => $version,
            'date' => Carbon::now()->toJSON(),
            'called_from' => __CLASS__,
        ];

        File::ensureDirectoryExists(storage_path('bar-assistant/backups'));
        $filename = storage_path(sprintf('bar-assistant/backups/%s_%s.zip', Carbon::now()->format('Ymdhi'), 'recipes'));
        if ($exportPath) {
            $filename = $exportPath;
        }

        $zip = new ZipArchive();

        if ($zip->open($filename, ZipArchive::CREATE) !== true) {
            $message = sprintf('Error creating zip archive with filepath "%s"', $filename);

            throw new ExportFileNotCreatedException($message);
        }

        $this->dumpCocktails($barId, $zip, $exportType);
        $this->dumpIngredients($barId, $zip, $exportType);
        $this->dumpBaseData($barId, $zip, $exportType);

        if ($metaContent = json_encode($meta)) {
            $zip->addFromString('_meta.json', $metaContent);
        }

        $zip->close();

        return $filename;
    }

    private function dumpCocktails(int $barId, ZipArchive &$zip, ExportTypeEnum $type): void
    {
        $cocktails = Cocktail::with(['ingredients.ingredient', 'ingredients.substitutes', 'images' => function ($query) {
            $query->orderBy('sort');
        }, 'glass', 'method', 'tags'])->where('bar_id', $barId)->get();

        foreach ($cocktails as $cocktail) {
            $data = $cocktail->share();

            if ($type === ExportTypeEnum::YAML) {
                $cocktailExportData = Yaml::dump($data, 8, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            } else {
                $cocktailExportData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            $zip->addFromString('cocktails/' . $data['_id'] . '.' . $type->value, $cocktailExportData);

            $i = 1;
            foreach ($cocktail->images as $img) {
                $zip->addFile($img->getPath(), 'cocktails/images/' . $data['_id'] . '-' . $i . '.' . $img->file_extension);
                $i++;
            }
        }
    }

    private function dumpIngredients(int $barId, ZipArchive &$zip, ExportTypeEnum $type): void
    {
        $ingredients = Ingredient::with(['images' => function ($query) {
            $query->orderBy('sort');
        }])->where('bar_id', $barId)->get();

        foreach ($ingredients as $ingredient) {
            $data = $ingredient->share();

            if ($type === ExportTypeEnum::YAML) {
                $ingredientExportData = Yaml::dump($data, 8, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            } else {
                $ingredientExportData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            $zip->addFromString('ingredients/' . $data['_id'] . '.' . $type->value, $ingredientExportData);

            $i = 1;
            foreach ($ingredient->images as $img) {
                $zip->addFile($img->getPath(), 'ingredients/images/' . $data['_id'] . '-' . $i . '.' . $img->file_extension);
                $i++;
            }
        }
    }

    private function dumpBaseData(int $barId, ZipArchive &$zip, ExportTypeEnum $type): void
    {
        $baseDataFiles = [
            'base_glasses' => DB::table('glasses')->select('name', 'description')->where('bar_id', $barId)->get()->toArray(),
            'base_methods' => DB::table('cocktail_methods')->select('name', 'dilution_percentage')->where('bar_id', $barId)->get()->toArray(),
            'base_utensils' => DB::table('utensils')->select('name', 'description')->where('bar_id', $barId)->get()->toArray(),
            'base_ingredient_categories' => DB::table('ingredient_categories')->select('name', 'description')->where('bar_id', $barId)->get()->toArray(),
        ];

        foreach ($baseDataFiles as $file => $data) {
            if ($type === ExportTypeEnum::YAML) {
                $exportData = Yaml::dump($data, 8, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_OBJECT_AS_MAP);
            } else {
                $exportData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            $zip->addFromString($file . '.' . $type->value, $exportData);
        }
    }
}

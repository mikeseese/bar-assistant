<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Services\IngredientService;
use Illuminate\Http\Resources\Json\JsonResource;
use Kami\Cocktail\Repository\CocktailRepository;
use Kami\Cocktail\Http\Requests\IngredientRequest;
use Kami\Cocktail\Repository\IngredientRepository;
use Kami\Cocktail\Http\Resources\IngredientResource;
use Kami\Cocktail\Http\Filters\IngredientQueryFilter;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Kami\Cocktail\DataObjects\Ingredient\Ingredient as IngredientDTO;

class IngredientController extends Controller
{
    public function index(IngredientRepository $ingredientQuery, Request $request): JsonResource
    {
        try {
            $ingredients = (new IngredientQueryFilter($ingredientQuery))->paginate($request->get('per_page', 50))->withQueryString();
        } catch (InvalidFilterQuery $e) {
            abort(400, $e->getMessage());
        }

        return IngredientResource::collection($ingredients);
    }

    public function show(Request $request, int|string $id): JsonResource
    {
        $ingredient = Ingredient::with('cocktails', 'images', 'varieties', 'parentIngredient', 'createdUser', 'updatedUser')
            ->withCount('cocktails')
            ->where('id', $id)
            ->orWhere('slug', $id)
            ->firstOrFail();

        if ($request->user()->cannot('show', $ingredient)) {
            abort(403);
        }

        return new IngredientResource($ingredient);
    }

    public function store(IngredientService $ingredientService, IngredientRequest $request): JsonResponse
    {
        if ($request->user()->cannot('create', Ingredient::class)) {
            abort(403);
        }

        $ingredientDTO = new IngredientDTO(
            bar()->id,
            $request->post('name'),
            auth()->user()->id,
            $request->post('ingredient_category_id') ? (int) $request->post('ingredient_category_id') : null,
            floatval($request->post('strength', '0')),
            $request->post('description'),
            $request->post('origin'),
            $request->post('color'),
            $request->post('parent_ingredient_id') ? (int) $request->post('parent_ingredient_id') : null,
            $request->post('images', []),
        );

        $ingredient = $ingredientService->createIngredient($ingredientDTO);

        return (new IngredientResource($ingredient))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('ingredients.show', $ingredient->id));
    }

    public function update(IngredientService $ingredientService, IngredientRequest $request, int $id): JsonResource
    {
        $ingredient = Ingredient::findOrFail($id);

        if ($request->user()->cannot('edit', $ingredient)) {
            abort(403);
        }

        $ingredientDTO = new IngredientDTO(
            $ingredient->bar_id,
            $request->post('name'),
            auth()->user()->id,
            $request->post('ingredient_category_id') ? (int) $request->post('ingredient_category_id') : null,
            floatval($request->post('strength', '0')),
            $request->post('description'),
            $request->post('origin'),
            $request->post('color'),
            $request->post('parent_ingredient_id') ? (int) $request->post('parent_ingredient_id') : null,
            $request->post('images', []),
        );

        $ingredient = $ingredientService->updateIngredient($id, $ingredientDTO);

        return new IngredientResource($ingredient);
    }

    public function delete(Request $request, int $id): Response
    {
        $ingredient = Ingredient::findOrFail($id);

        if ($request->user()->cannot('delete', $ingredient)) {
            abort(403);
        }

        $ingredient->delete();

        return response(null, 204);
    }

    public function extra(Request $request, CocktailRepository $cocktailRepo, int $id): JsonResponse
    {
        $ingredient = Ingredient::findOrFail($id);

        if ($request->user()->cannot('show', $ingredient)) {
            abort(403);
        }

        $currentShelfIngredients = $request->user()->getShelfIngredients($ingredient->bar_id)->pluck('ingredient_id');
        $currentShelfCocktails = $cocktailRepo->getCocktailsByIngredients($currentShelfIngredients->toArray())->values();
        $extraShelfCocktails = $cocktailRepo->getCocktailsByIngredients($currentShelfIngredients->push($ingredient->id)->toArray())->values();

        if ($currentShelfCocktails->count() === $extraShelfCocktails->count()) {
            return response()->json(['data' => []]);
        }

        $extraCocktails = Cocktail::whereIn('id', $extraShelfCocktails->diff($currentShelfCocktails)->values())->where('bar_id', '=', $ingredient->bar_id)->get();

        return response()->json([
            'data' => $extraCocktails->map(function (Cocktail $cocktail) {
                return [
                    'id' => $cocktail->id,
                    'slug' => $cocktail->slug,
                    'name' => $cocktail->name,
                ];
            })
        ]);
    }

    public function recommend(Request $request, IngredientRepository $ingredientRepo): JsonResponse
    {
        $barMembership = $request->user()->getBarMembership(bar()->id);

        if (!$barMembership) {
            abort(404);
        }

        $possibleIngredients = $ingredientRepo->getIngredientsForPossibleCocktails(bar()->id, $barMembership->id);

        return response()->json(['data' => $possibleIngredients]);
    }
}

<?php

namespace App\Services\Promotion;

use App\Contracts\Repositories\PromotionRepositoryInterface;
use App\DTOs\Promotion\CreatePromotionData;
use App\DTOs\Promotion\PromotionQuery;
use App\DTOs\Promotion\UpdatePromotionData;
use App\Enums\PromotionType;
use App\Exceptions\Domain\AdministrationConflictException;
use App\Exceptions\Domain\PromotionUsageLimitConflictException;
use App\Models\Promotion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PromotionAdministrationService
{
    public function __construct(private PromotionRepositoryInterface $promotions) {}

    /** @return LengthAwarePaginator<int, Promotion> */
    public function listPromotions(User $user, PromotionQuery $query): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('viewAny', Promotion::class);

        return $this->promotions->paginate($query->page, $query->perPage, $query->isActive);
    }

    public function getPromotion(User $user, string $id): Promotion
    {
        Gate::forUser($user)->authorize('view', Promotion::class);

        return $this->promotions->findById($this->promotionId($id))
            ?? throw (new ModelNotFoundException)->setModel(Promotion::class, [$id]);
    }

    public function createPromotion(User $user, CreatePromotionData $data): Promotion
    {
        Gate::forUser($user)->authorize('create', Promotion::class);
        $attributes = $data->toPersistenceArray();
        $this->validateProperties($attributes);

        return $this->mutate(fn (): Promotion => $this->promotions->create($attributes));
    }

    public function updatePromotion(User $user, string $id, UpdatePromotionData $data): Promotion
    {
        Gate::forUser($user)->authorize('update', Promotion::class);
        $promotionId = $this->promotionId($id);
        $attributes = $data->toPersistenceArray();

        return $this->mutate(function () use ($promotionId, $attributes): Promotion {
            $promotion = $this->promotions->findByIdForUpdate($promotionId)
                ?? throw (new ModelNotFoundException)->setModel(Promotion::class, [$promotionId]);
            $this->validateProperties(array_replace([
                'type' => $promotion->type->value, 'value' => $promotion->value,
                'starts_at' => $promotion->starts_at, 'expires_at' => $promotion->expires_at,
            ], $attributes));

            /** All redemption writers hold this same promotion lock until their ledger insert commits. */
            if ((isset($attributes['global_usage_limit'])
                    && $attributes['global_usage_limit'] < $this->promotions->redemptionCounts($promotion, 0)['global'])
                || (isset($attributes['per_customer_usage_limit'])
                    && $attributes['per_customer_usage_limit'] < $this->promotions->maximumCustomerRedemptions($promotion))) {
                throw new PromotionUsageLimitConflictException;
            }

            return $this->promotions->update($promotion, $attributes);
        });
    }

    /** @param array{type: string, value: int, starts_at?: mixed, expires_at?: mixed} $attributes */
    private function validateProperties(array $attributes): void
    {
        if ($attributes['type'] === PromotionType::Percentage->value && $attributes['value'] > 10000) {
            throw ValidationException::withMessages(['value' => ['A percentage discount must not exceed 10000 basis points.']]);
        }

        $startsAt = $attributes['starts_at'] ?? null;
        $expiresAt = $attributes['expires_at'] ?? null;

        if ($startsAt !== null && $expiresAt !== null && CarbonImmutable::parse($startsAt)->gte(CarbonImmutable::parse($expiresAt))) {
            throw ValidationException::withMessages(['expires_at' => ['The expiration must be after the start date.']]);
        }
    }

    private function promotionId(string $id): int
    {
        $promotionId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($promotionId === false) {
            throw (new ModelNotFoundException)->setModel(Promotion::class, [$id]);
        }

        return $promotionId;
    }

    /** @param Closure(): Promotion $operation */
    private function mutate(Closure $operation): Promotion
    {
        try {
            return DB::transaction($operation, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'promotions_code_unique')) {
                throw ValidationException::withMessages(['code' => ['The code has already been taken.']]);
            }

            throw $exception;
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (str_starts_with($sqlState, '23') || in_array($sqlState, ['40001', '40P01', '55P03'], true)) {
                throw new AdministrationConflictException;
            }

            throw $exception;
        }
    }
}

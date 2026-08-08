<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BrandAsset;
use App\Models\User;

class BrandAssetPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->puede($user, 'knowledge.view');
    }

    public function view(User $user, BrandAsset $asset): bool
    {
        return $this->puede($user, 'knowledge.view')
            && $this->alcanzaMarca($user, $asset->brand_id);
    }

    public function create(User $user): bool
    {
        return $this->puede($user, 'knowledge.edit');
    }

    public function update(User $user, BrandAsset $asset): bool
    {
        return $this->puede($user, 'knowledge.edit')
            && $this->alcanzaMarca($user, $asset->brand_id);
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}

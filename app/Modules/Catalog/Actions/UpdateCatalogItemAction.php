<?php

namespace App\Modules\Catalog\Actions;

use App\Models\User;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Support\CatalogImageStorage;
use App\Modules\CommercialProposals\Domain\ValueObjects\Money;
use App\Support\Audit\Audit;
use Illuminate\Http\UploadedFile;

final class UpdateCatalogItemAction
{
    public function __construct(
        private readonly CatalogImageStorage $images = new CatalogImageStorage,
    ) {}

    /**
     * Изменение позиции не меняет уже сохранённые КП — там snapshot
     * (план §7.3). Явное обновление строк черновика — отдельное действие
     * RefreshDraftItemsFromCatalogAction. Картинка меняется только при
     * явной загрузке нового файла или флаге remove_image; иначе прежняя.
     *
     * @param  array{name: string, category_id?: ?int, description?: ?string, unit?: ?string, price: string, active?: bool, image?: UploadedFile|null, remove_image?: bool}  $data
     */
    public function execute(User $actor, CatalogItem $item, array $data): CatalogItem
    {
        $before = $item->only(['name', 'unit', 'price', 'active', 'image']);

        $item->fill([
            'category_id' => $data['category_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'unit' => $data['unit'] ?? null,
            'price' => Money::fromDecimalString((string) $data['price'])->toDecimalString(),
            'active' => (bool) ($data['active'] ?? true),
        ]);

        if (! empty($data['remove_image'])) {
            $this->images->deleteIfManaged($item->image);
            $item->image = null;
        } elseif (($data['image'] ?? null) instanceof UploadedFile) {
            $this->images->deleteIfManaged($item->image);
            $item->image = $this->images->store($data['image']);
        }

        $item->save();

        Audit::record($actor, 'catalog-item.updated', $item, $before, [
            'name' => $item->name,
            'category' => $item->category?->name,
            'price' => $item->price,
            'active' => $item->active,
            'image' => $item->image,
        ]);

        return $item;
    }
}

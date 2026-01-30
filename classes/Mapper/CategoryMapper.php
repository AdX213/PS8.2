<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/CategoryMapRepository.php';

class CategoryMapper
{
    /**
     * Zwraca externalCategories do payloadu ERLI.
     * wysyłamy już konkretne ID kategorii ERLI (source=marketplace)
     * @return array
     */
    public static function mapProductCategories(Product $product, $idLang)
    {
        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        $idLang = (int) $idLang;

        $repo = new CategoryMapRepository();

        // 1) Weź domyślną kategorię produktu jako główną
        $idCategory = (int) $product->id_category_default;

        // 2) Jeśli brak domyślnej, weź pierwszą z przypisanych kategorii
        if ($idCategory <= 0) {
            $cats = $product->getCategories();
            if (!empty($cats)) {
                $idCategory = (int) $cats[0];
            }
        }

        if ($idCategory <= 0) {
            return [];
        }

        // 3) Sprawdź mapowanie PS -> ERLI
        $map = $repo->findByCategoryId($idCategory);
        if (!$map || empty($map['erli_category_id'])) {
            return [];
        }

        $erliId = (string) $map['erli_category_id'];

        // name nie jest wymagane dla source=marketplace, ale możesz zostawić
        $erliName = '';
        if (!empty($map['erli_category_name'])) {
            $erliName = (string) $map['erli_category_name'];
        } else {
            // fallback: nazwa kategorii w Preście (tylko informacyjnie)
            $cat = new Category((int)$idCategory, $idLang);
            if (Validate::isLoadedObject($cat)) {
                $erliName = (string) $cat->name;
            }
        }

        return [
            [
                'source' => 'marketplace',
                'breadcrumb' => [
                    [
                        'id'   => $erliId,
                        'name' => $erliName, 
                    ],
                ],
                
            ],
        ];
    }
}

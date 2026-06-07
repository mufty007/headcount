-- Potluck: retire ice/coolers, condiments, breakfast; split rice vs noodles/pasta (was rice_noodles).

UPDATE rsvps SET potluck_category = 'rice' WHERE potluck_category = 'rice_noodles';

UPDATE rsvps
SET potluck_category = 'other',
    potluck_item_note = CASE
        WHEN potluck_item_note IS NULL OR TRIM(potluck_item_note) = '' THEN 'Migrated from a retired potluck category—please update your item description if needed.'
        ELSE potluck_item_note
    END
WHERE potluck_category IN ('ice_coolers', 'condiments_seasonings', 'breakfast_brunch');

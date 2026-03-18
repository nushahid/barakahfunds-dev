<?php
/*
1) In the POST save block, after reading form values:

$form['city_id'] = max(0, (int)($_POST['city_id'] ?? 0));

2) Add city_id in $form defaults:
'city_id' => 0,

3) Add city_id to the SELECT load, INSERT, UPDATE and params arrays.

4) Replace the old City input with the block below.
5) Include the CSS and JS assets from /assets.
*/
?>
<div>
    <label for="city_search">City</label>
    <div class="city-combobox" id="city_combobox">
        <input type="text" id="city_search" class="city-search-input" placeholder="Type and search Italy comune" autocomplete="off" value="<?= e((string)$form['city']) ?>">
        <input type="hidden" name="city" id="city" value="<?= e((string)$form['city']) ?>">
        <input type="hidden" name="city_id" id="city_id" value="<?= (int)($form['city_id'] ?? 0) ?>">
        <button type="button" class="city-toggle-btn" id="city_toggle_btn" aria-label="Open city list">▾</button>
        <div class="city-dropdown" id="city_dropdown" hidden>
            <div class="city-dropdown-list" id="city_dropdown_list"></div>
        </div>
    </div>
    <small class="muted">Search and select from Italy comuni database.</small>
</div>
<link rel="stylesheet" href="assets/city-combobox.css">
<script src="assets/city-combobox.js"></script>
<script>
initCityCombobox({
    comboId: 'city_combobox',
    searchInputId: 'city_search',
    hiddenNameInputId: 'city',
    hiddenIdInputId: 'city_id',
    toggleBtnId: 'city_toggle_btn',
    dropdownId: 'city_dropdown',
    listId: 'city_dropdown_list',
    endpoint: 'ajax/search_comuni.php'
});
</script>

document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('city_autocomplete');
    const list = document.getElementById('city_autocomplete_list');
    const meta = document.getElementById('city_autocomplete_meta');

    if (!input || !list || !meta) {
        return;
    }

    let debounceTimer = null;
    let activeIndex = -1;
    let currentItems = [];
    let lastQuery = '';

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (m) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[m];
        });
    }

    function hideList() {
        list.hidden = true;
        list.innerHTML = '';
        activeIndex = -1;
        currentItems = [];
    }

    function clearMeta() {
        meta.textContent = '';
    }

    function showMeta(item) {
        const parts = [];

        if (item.province_name) {
            parts.push('Province: ' + item.province_name);
        }
        if (item.province_code) {
            parts.push('Code: ' + item.province_code);
        }
        if (item.region_name) {
            parts.push('Region: ' + item.region_name);
        }
        if (item.cap) {
            parts.push('CAP: ' + item.cap);
        }

        meta.textContent = parts.join(' | ');
    }

    function setActive(index) {
        const nodes = list.querySelectorAll('.city-autocomplete-item');
        nodes.forEach(function (node, i) {
            node.classList.toggle('active', i === index);
        });
        activeIndex = index;
    }

    function selectItem(item) {
        input.value = item.value || item.comune || '';
        showMeta(item);
        hideList();
    }

    function renderItems(items) {
        currentItems = items;
        activeIndex = -1;

        if (!items.length) {
            hideList();
            return;
        }

        list.innerHTML = items.map(function (item, index) {
            const mainText = item.comune || item.value || '';
            const subText = item.label || '';

            return `
                <button type="button" class="city-autocomplete-item" data-index="${index}">
                    <span class="city-autocomplete-main">${escapeHtml(mainText)}</span>
                    <span class="city-autocomplete-sub">${escapeHtml(subText)}</span>
                </button>
            `;
        }).join('');

        list.hidden = false;

        list.querySelectorAll('.city-autocomplete-item').forEach(function (button) {
            button.addEventListener('click', function () {
                const idx = parseInt(this.getAttribute('data-index'), 10);
                if (!Number.isNaN(idx) && currentItems[idx]) {
                    selectItem(currentItems[idx]);
                }
            });
        });
    }

    function fetchCities(query) {
        lastQuery = query;

        fetch('assets/search_italy_comuni.php?q=' + encodeURIComponent(query) + '&limit=10', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            if (input.value.trim() !== lastQuery) {
                return;
            }

            if (!data || data.ok !== true || !Array.isArray(data.items)) {
                hideList();
                return;
            }

            renderItems(data.items);
        })
        .catch(function (error) {
            console.error('City autocomplete error:', error);
            hideList();
        });
    }

    input.addEventListener('input', function () {
        const query = input.value.trim();

        clearMeta();

        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        if (query.length < 2) {
            hideList();
            return;
        }

        debounceTimer = setTimeout(function () {
            fetchCities(query);
        }, 250);
    });

    input.addEventListener('keydown', function (e) {
        const count = currentItems.length;

        if (!count || list.hidden) {
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const next = activeIndex < count - 1 ? activeIndex + 1 : 0;
            setActive(next);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prev = activeIndex > 0 ? activeIndex - 1 : count - 1;
            setActive(prev);
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && currentItems[activeIndex]) {
                e.preventDefault();
                selectItem(currentItems[activeIndex]);
            }
        } else if (e.key === 'Escape') {
            hideList();
        }
    });

    input.addEventListener('blur', function () {
        setTimeout(function () {
            hideList();
        }, 150);
    });

    if (input.value.trim() !== '') {
        clearMeta();
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.city-autocomplete-wrap')) {
            hideList();
        }
    });
});
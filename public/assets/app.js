(() => {
    const mobileMenuButton = document.querySelector('[data-mobile-menu-toggle]');
    const mobileMenu = document.querySelector('[data-mobile-menu]');
    const sidebar = mobileMenuButton?.closest('.sidebar');
    if (mobileMenuButton && mobileMenu && sidebar) {
        const mobileQuery = window.matchMedia('(max-width: 980px)');
        const setMenuOpen = (open) => {
            sidebar.classList.toggle('is-open', open);
            mobileMenuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        mobileMenuButton.addEventListener('click', () => {
            setMenuOpen(!sidebar.classList.contains('is-open'));
        });

        mobileMenu.addEventListener('click', (event) => {
            if (mobileQuery.matches && event.target.closest('a')) {
                setMenuOpen(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
            }
        });

        mobileQuery.addEventListener?.('change', (event) => {
            if (!event.matches) {
                setMenuOpen(false);
            }
        });
    }

    const initSettingsBoard = () => {
        const board = document.querySelector('[data-settings-board]');
        if (!board) {
            return;
        }

        const orderKey = 'takip.settings.cardOrder';

        const readJson = (key, fallback) => {
            try {
                const value = window.localStorage?.getItem(key);
                return value ? JSON.parse(value) : fallback;
            } catch (error) {
                return fallback;
            }
        };

        const writeJson = (key, value) => {
            try {
                window.localStorage?.setItem(key, JSON.stringify(value));
            } catch (error) {
                // Tarayıcı depolaması kapalıysa ayarlar yine normal çalışır.
            }
        };

        try {
            window.localStorage?.removeItem('takip.settings.activeCard');
            window.localStorage?.removeItem('takip.settings.cardOpen');
        } catch (error) {
            // Eski ayarlar görünümü kayıtları temizlenemese de ekran çalışmaya devam eder.
        }

        const allCards = () => Array.from(document.querySelectorAll('[data-settings-card]'));
        const boardCards = () => Array.from(board.querySelectorAll(':scope > [data-settings-card]'));

        const flattenCards = () => {
            Array.from(board.querySelectorAll('[data-settings-card]')).forEach((card) => board.appendChild(card));
            board.querySelectorAll('.settings-side').forEach((side) => {
                if (!side.querySelector('[data-settings-card]')) {
                    side.remove();
                }
            });
        };

        const dialog = document.createElement('dialog');
        dialog.className = 'app-dialog settings-dialog';
        dialog.innerHTML = `
            <div class="app-dialog-body settings-dialog-body">
                <div class="section-head dialog-head">
                    <div>
                        <h2 data-settings-dialog-title>Ayar</h2>
                        <span>Ayarları düzenleyip kaydettiğinizde pencere kapanır ve kutular pasif hale döner.</span>
                    </div>
                    <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
                </div>
                <div class="settings-dialog-slot" data-settings-dialog-slot></div>
            </div>
        `;
        document.body.appendChild(dialog);
        const dialogSlot = dialog.querySelector('[data-settings-dialog-slot]');
        const dialogTitle = dialog.querySelector('[data-settings-dialog-title]');

        const savedOrder = readJson(orderKey, []);
        flattenCards();
        const cardsByKey = new Map(boardCards().map((card) => [card.dataset.settingsKey || '', card]));
        savedOrder.forEach((key) => {
            const card = cardsByKey.get(key);
            if (card) {
                board.appendChild(card);
            }
        });
        boardCards().forEach((card) => {
            if (!savedOrder.includes(card.dataset.settingsKey || '')) {
                board.appendChild(card);
            }
        });

        const saveOrder = () => {
            const currentOrder = readJson(orderKey, []);
            const visibleKeys = boardCards().map((card) => card.dataset.settingsKey || '').filter(Boolean);
            const activeCard = dialogSlot.querySelector('[data-settings-card]');
            const selectedKey = activeCard?.dataset.settingsKey || '';
            const ordered = currentOrder.filter((key) => key === selectedKey || visibleKeys.includes(key));
            visibleKeys.forEach((key) => {
                if (!ordered.includes(key)) {
                    ordered.push(key);
                }
            });
            if (selectedKey && !ordered.includes(selectedKey)) {
                ordered.push(selectedKey);
            }
            writeJson(orderKey, ordered);
        };

        const restoreBoardOrder = () => {
            const order = readJson(orderKey, []);
            const map = new Map(boardCards().map((card) => [card.dataset.settingsKey || '', card]));
            order.forEach((key) => {
                const card = map.get(key);
                if (card) {
                    board.appendChild(card);
                }
            });
            boardCards().forEach((card) => {
                if (!order.includes(card.dataset.settingsKey || '')) {
                    board.appendChild(card);
                }
            });
        };

        const collapseCard = (card) => {
            const toggleButton = card.querySelector('.settings-toggle');
            card.classList.add('settings-card-collapsed');
            card.classList.remove('settings-card-active');
            if (toggleButton) {
                toggleButton.textContent = 'Ayarla';
                toggleButton.setAttribute('aria-expanded', 'false');
            }
        };

        const closeActiveCard = () => {
            const activeCard = dialogSlot.querySelector('[data-settings-card]');
            if (!activeCard) {
                return;
            }

            collapseCard(activeCard);
            board.appendChild(activeCard);
            restoreBoardOrder();
        };

        const openCard = (card) => {
            const currentActive = dialogSlot.querySelector('[data-settings-card]');
            if (currentActive === card) {
                dialog.close();
                return;
            }

            if (currentActive) {
                collapseCard(currentActive);
                board.appendChild(currentActive);
            }

            allCards().forEach((item) => {
                if (item !== card) {
                    collapseCard(item);
                }
            });

            card.classList.remove('settings-card-collapsed');
            card.classList.add('settings-card-active');
            const toggleButton = card.querySelector('.settings-toggle');
            toggleButton.textContent = 'Kapat';
            toggleButton.setAttribute('aria-expanded', 'true');
            const title = card.querySelector(':scope > .section-head h2')?.textContent?.trim() || 'Ayar';
            if (dialogTitle) {
                dialogTitle.textContent = title;
            }
            dialogSlot.appendChild(card);
            restoreBoardOrder();
            if (dialog.showModal && !dialog.open) {
                dialog.showModal();
            }
        };

        dialog.addEventListener('close', closeActiveCard);

        allCards().forEach((card) => {
            if (card.dataset.settingsPrepared === '1') {
                return;
            }

            const key = card.dataset.settingsKey || '';
            const header = Array.from(card.children).find((child) => child.classList?.contains('section-head'));
            if (!header) {
                return;
            }

            const body = document.createElement('div');
            body.className = 'settings-card-body';
            while (header.nextSibling) {
                body.appendChild(header.nextSibling);
            }
            card.appendChild(body);

            const tools = document.createElement('div');
            tools.className = 'settings-card-tools';

            const dragHandle = document.createElement('button');
            dragHandle.type = 'button';
            dragHandle.className = 'settings-drag-handle';
            dragHandle.setAttribute('aria-label', 'Ayar kartını sırala');
            dragHandle.textContent = 'Sırala';

            const toggleButton = document.createElement('button');
            toggleButton.type = 'button';
            toggleButton.className = 'settings-toggle';
            toggleButton.setAttribute('aria-expanded', 'false');

            tools.append(dragHandle, toggleButton);
            header.appendChild(tools);

            collapseCard(card);

            header.addEventListener('click', (event) => {
                if (event.target.closest('a, button, input, select, textarea, label')) {
                    return;
                }
                if (card.parentElement === dialogSlot) {
                    return;
                }
                openCard(card);
            });

            toggleButton.addEventListener('click', () => {
                openCard(card);
            });

            let dragFromHandle = false;
            dragHandle.addEventListener('pointerdown', () => {
                dragFromHandle = true;
            });
            dragHandle.addEventListener('pointerup', () => {
                dragFromHandle = false;
            });
            dragHandle.addEventListener('pointercancel', () => {
                dragFromHandle = false;
            });
            dragHandle.addEventListener('keydown', () => {
                dragFromHandle = true;
            });
            dragHandle.addEventListener('keyup', () => {
                dragFromHandle = false;
            });

            card.draggable = true;
            card.addEventListener('dragstart', (event) => {
                if (card.parentElement !== board) {
                    event.preventDefault();
                    return;
                }

                if (!dragFromHandle && !event.target.closest('.settings-drag-handle')) {
                    event.preventDefault();
                    return;
                }

                card.classList.add('settings-card-dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', key);
            });

            card.addEventListener('dragend', () => {
                dragFromHandle = false;
                card.classList.remove('settings-card-dragging');
                boardCards().forEach((item) => item.classList.remove('settings-card-drag-over'));
                saveOrder();
            });

            card.dataset.settingsPrepared = '1';
        });

        const hashTarget = window.location.hash ? document.querySelector(window.location.hash) : null;
        if (hashTarget?.matches('[data-settings-card]')) {
            openCard(hashTarget);
        }

        const getDragAfterElement = (y) => boardCards()
            .filter((card) => !card.classList.contains('settings-card-dragging'))
            .reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - (box.height / 2);
                if (offset < 0 && offset > closest.offset) {
                    return { offset, element: child };
                }
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;

        board.addEventListener('dragover', (event) => {
            const dragging = board.querySelector(':scope > .settings-card-dragging');
            if (!dragging) {
                return;
            }

            event.preventDefault();
            const afterElement = getDragAfterElement(event.clientY);
            boardCards().forEach((card) => card.classList.remove('settings-card-drag-over'));
            if (afterElement) {
                afterElement.classList.add('settings-card-drag-over');
                board.insertBefore(dragging, afterElement);
            } else {
                board.appendChild(dragging);
            }
        });

        board.addEventListener('drop', (event) => {
            if (board.querySelector(':scope > .settings-card-dragging')) {
                event.preventDefault();
                saveOrder();
            }
        });
    };

    initSettingsBoard();

    const contactRow = (index) => `
        <div class="contact-entry" data-contact-row>
            <label>Yetkili adı <input name="contacts[${index}][full_name]" data-contact-name></label>
            <label>E-posta <input type="email" name="contacts[${index}][email]" data-contact-email></label>
            <label>Telefon <input name="contacts[${index}][phone]" data-contact-phone></label>
            <label class="checkline"><input type="checkbox" name="contacts[${index}][notify_enabled]" value="1" checked> Bilgilendirme gönder</label>
            <button type="button" class="button small danger" data-remove-contact>Kaldır</button>
        </div>
    `;

    const addContactRow = (contactEditor) => {
        if (!contactEditor) {
            return null;
        }

        const list = contactEditor.querySelector('[data-contact-list]');
        if (!list) {
            return null;
        }

        const index = Number(contactEditor.dataset.nextIndex || '0');
        contactEditor.dataset.nextIndex = String(index + 1);
        list.insertAdjacentHTML('beforeend', contactRow(index));

        return list.lastElementChild;
    };

    document.querySelectorAll('[data-contact-editor]').forEach((contactEditor) => {
        const addButton = contactEditor.querySelector('[data-add-contact]');
        addButton?.addEventListener('click', () => addContactRow(contactEditor));

        contactEditor.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-remove-contact]');
            if (!removeButton) {
                return;
            }

            const row = removeButton.closest('[data-contact-row]');
            row?.remove();

            if (!contactEditor.querySelector('[data-contact-row]')) {
                addContactRow(contactEditor);
            }
        });
    });

    document.querySelectorAll('[data-customer-board]').forEach((board) => {
        const input = board.querySelector('[data-customer-filter-input]');
        const cards = Array.from(board.querySelectorAll('[data-customer-card]'));
        const emptyState = board.querySelector('[data-customer-empty]');
        const status = board.querySelector('[data-customer-filter-status]');
        const normalizeText = (value) => String(value || '')
            .toLocaleLowerCase('tr-TR')
            .replaceAll('ı', 'i')
            .replaceAll('ğ', 'g')
            .replaceAll('ü', 'u')
            .replaceAll('ş', 's')
            .replaceAll('ö', 'o')
            .replaceAll('ç', 'c')
            .replaceAll('İ', 'i')
            .replaceAll('Ğ', 'g')
            .replaceAll('Ü', 'u')
            .replaceAll('Ş', 's')
            .replaceAll('Ö', 'o')
            .replaceAll('Ç', 'c')
            .trim();

        const syncCustomerFilter = () => {
            const query = normalizeText(input?.value || '');
            let visible = 0;
            cards.forEach((card) => {
                const name = normalizeText(card.dataset.customerName || '');
                const haystack = normalizeText(card.dataset.customerFilter || '');
                const matches = query === ''
                    || name.startsWith(query)
                    || (query.length > 1 && haystack.includes(query));
                card.hidden = !matches;
                if (matches) {
                    visible += 1;
                }
            });
            if (emptyState) {
                emptyState.hidden = visible !== 0;
            }
            if (status) {
                status.textContent = `${visible} cari listeleniyor`;
            }
        };

        input?.addEventListener('input', syncCustomerFilter);
        syncCustomerFilter();
    });

    const reminderEditor = document.querySelector('[data-reminder-editor]');
    const reminderRow = (index) => `
        <div class="contact-entry reminder-entry" data-reminder-row>
            <label>Gün sayısı <input type="number" min="1" name="reminder_rules[${index}]" value="15"></label>
            <button type="button" class="button small danger" data-remove-reminder>Kaldır</button>
        </div>
    `;

    const addReminderRow = () => {
        if (!reminderEditor) {
            return null;
        }

        const list = reminderEditor.querySelector('[data-reminder-list]');
        const index = Number(reminderEditor.dataset.nextIndex || '0');
        reminderEditor.dataset.nextIndex = String(index + 1);
        list.insertAdjacentHTML('beforeend', reminderRow(index));

        return list.lastElementChild;
    };

    if (reminderEditor) {
        const addButton = reminderEditor.querySelector('[data-add-reminder]');
        addButton?.addEventListener('click', addReminderRow);

        reminderEditor.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-remove-reminder]');
            if (!removeButton || removeButton.disabled) {
                return;
            }

            const row = removeButton.closest('[data-reminder-row]');
            row?.remove();

            if (!reminderEditor.querySelector('[data-reminder-row]')) {
                addReminderRow();
            }
        });
    }

    const productEditor = document.querySelector('[data-renewal-items]');
    if (productEditor) {
        const productList = productEditor.querySelector('[data-renewal-item-list]');
        const totalOutput = productEditor.querySelector('[data-renewal-items-total]');
        const currencySelect = productEditor.querySelector('[data-renewal-currency]');
        const moneyFormatter = () => new Intl.NumberFormat('tr-TR', {
            style: 'currency',
            currency: currencySelect?.value || 'TRY',
        });

        const numberValue = (input, fallback = 0) => {
            const value = String(input?.value || '').replace(',', '.');
            const parsed = Number(value);

            return Number.isFinite(parsed) ? parsed : fallback;
        };

        const syncItemKind = (row) => {
            const definition = row.querySelector('[data-item-definition]');
            const kind = row.querySelector('[data-item-kind]');
            const selectedKind = definition?.selectedOptions[0]?.dataset.kind;
            if (selectedKind && kind) {
                kind.value = selectedKind;
            }
        };

        const syncTotals = () => {
            let total = 0;
            productList?.querySelectorAll('[data-renewal-item-row]').forEach((row) => {
                const quantity = Math.max(0, numberValue(row.querySelector('[data-line-quantity]'), 1));
                const price = Math.max(0, numberValue(row.querySelector('[data-line-price]'), 0));
                const vat = Math.max(0, numberValue(row.querySelector('[data-line-vat]'), 0));
                const lineTotal = quantity * price * (1 + vat / 100);
                total += lineTotal;
                const lineOutput = row.querySelector('[data-line-total]');
                if (lineOutput) {
                    lineOutput.textContent = `Toplam: ${moneyFormatter().format(lineTotal)} KDV dahil`;
                }
            });
            if (totalOutput) {
                totalOutput.textContent = moneyFormatter().format(total);
            }
        };

        const updateRemoveButtons = () => {
            const rows = productList?.querySelectorAll('[data-renewal-item-row]') || [];
            rows.forEach((row) => {
                const button = row.querySelector('[data-remove-renewal-item]');
                if (button) {
                    button.disabled = rows.length <= 1;
                }
            });
        };

        const prepareClonedRow = (row, index) => {
            row.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace(/items\[[^\]]+\]/, `items[${index}]`);
                if (field.matches('select')) {
                    field.selectedIndex = 0;
                } else if (field.matches('[data-line-quantity]')) {
                    field.value = '1';
                } else if (field.matches('[data-line-vat]')) {
                    field.value = '20';
                } else {
                    field.value = '';
                }
            });
            const title = row.querySelector('.item-row-head strong');
            if (title) {
                title.textContent = `Ürün satırı ${index + 1}`;
            }
            syncItemKind(row);
        };

        productEditor.querySelector('[data-add-renewal-item]')?.addEventListener('click', () => {
            const source = productList?.querySelector('[data-renewal-item-row]');
            if (!source || !productList) {
                return;
            }
            const index = Number(productEditor.dataset.nextIndex || '0');
            productEditor.dataset.nextIndex = String(index + 1);
            const clone = source.cloneNode(true);
            prepareClonedRow(clone, index);
            productList.appendChild(clone);
            updateRemoveButtons();
            syncTotals();
        });

        productEditor.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-remove-renewal-item]');
            if (!removeButton || removeButton.disabled) {
                return;
            }
            removeButton.closest('[data-renewal-item-row]')?.remove();
            updateRemoveButtons();
            syncTotals();
        });

        productEditor.addEventListener('input', syncTotals);
        productEditor.addEventListener('change', (event) => {
            const row = event.target.closest('[data-renewal-item-row]');
            if (row && event.target.matches('[data-item-definition]')) {
                syncItemKind(row);
            }
            syncTotals();
        });
        currencySelect?.addEventListener('change', syncTotals);
        productList?.querySelectorAll('[data-renewal-item-row]').forEach(syncItemKind);
        updateRemoveButtons();
        syncTotals();
    }

    document.querySelectorAll('[data-customer-offer-form]').forEach((offerForm) => {
        const currencySelect = offerForm.querySelector('[data-customer-offer-currency]');
        const subtotalOutput = offerForm.querySelector('[data-customer-offer-subtotal]');
        const vatOutput = offerForm.querySelector('[data-customer-offer-vat]');
        const totalOutput = offerForm.querySelector('[data-customer-offer-total]');
        const numberValue = (input, fallback = 0) => {
            const value = String(input?.value || '').replace(',', '.');
            const parsed = Number(value);

            return Number.isFinite(parsed) ? parsed : fallback;
        };
        const moneyFormatter = () => new Intl.NumberFormat('tr-TR', {
            style: 'currency',
            currency: currencySelect?.value || 'TRY',
        });

        const syncCustomerOfferTotals = () => {
            let subtotal = 0;
            let vatTotal = 0;
            let total = 0;
            offerForm.querySelectorAll('[data-customer-offer-line]').forEach((line) => {
                const quantity = Math.max(0, numberValue(line.querySelector('[data-customer-offer-qty]'), 1));
                const unitPrice = Math.max(0, numberValue(line.querySelector('[data-customer-offer-unit]'), 0));
                const vatRate = Math.max(0, numberValue(line.querySelector('[data-customer-offer-vat-rate]'), 0));
                const lineSubtotal = quantity * unitPrice;
                const lineVat = lineSubtotal * (vatRate / 100);
                const lineTotal = lineSubtotal + lineVat;
                subtotal += lineSubtotal;
                vatTotal += lineVat;
                total += lineTotal;

                const unitPreview = line.querySelector('[data-customer-offer-unit-preview]');
                const subtotalPreview = line.querySelector('[data-customer-offer-subtotal-preview]');
                const totalPreview = line.querySelector('[data-customer-offer-total-preview]');
                if (unitPreview) {
                    unitPreview.textContent = moneyFormatter().format(unitPrice);
                }
                if (subtotalPreview) {
                    subtotalPreview.textContent = moneyFormatter().format(lineSubtotal);
                }
                if (totalPreview) {
                    totalPreview.textContent = moneyFormatter().format(lineTotal);
                }
            });

            if (subtotalOutput) {
                subtotalOutput.textContent = moneyFormatter().format(subtotal);
            }
            if (vatOutput) {
                vatOutput.textContent = moneyFormatter().format(vatTotal);
            }
            if (totalOutput) {
                totalOutput.textContent = moneyFormatter().format(total);
            }
        };

        offerForm.addEventListener('input', syncCustomerOfferTotals);
        offerForm.addEventListener('change', syncCustomerOfferTotals);
        syncCustomerOfferTotals();
    });

    document.querySelectorAll('[data-offer-builder-form]').forEach((offerForm) => {
        const currencySelect = offerForm.querySelector('[data-offer-builder-currency]');
        const subtotalOutput = offerForm.querySelector('[data-offer-subtotal]');
        const vatOutput = offerForm.querySelector('[data-offer-vat]');
        const totalOutput = offerForm.querySelector('[data-offer-total]');
        const list = offerForm.querySelector('[data-offer-line-list]');
        const template = offerForm.querySelector('[data-offer-line-template]');
        const addButton = offerForm.querySelector('[data-offer-add-line]');
        const stockSearchUrl = offerForm.dataset.stockSearchUrl || '';
        const stockList = document.getElementById('stock-item-options');
        const stockCache = new Map();
        let stockSearchTimer = null;
        const numberValue = (input, fallback = 0) => {
            const value = String(input?.value || '').replace(',', '.');
            const parsed = Number(value);

            return Number.isFinite(parsed) ? parsed : fallback;
        };
        const formatter = () => new Intl.NumberFormat('tr-TR', {
            style: 'currency',
            currency: currencySelect?.value || 'TRY',
        });
        const rows = () => Array.from(offerForm.querySelectorAll('[data-offer-line]'));
        const optionDisplayValue = (item) => [item.name, item.code, item.brand].filter(Boolean).join(' | ');
        const appendStockOptions = (items) => {
            if (!stockList || !Array.isArray(items)) {
                return;
            }
            const existingIds = new Set(Array.from(stockList.options).map((option) => option.dataset.stockId || ''));
            items.forEach((item) => {
                const id = String(item.id || '');
                const name = String(item.name || '').trim();
                if (!id || !name || existingIds.has(id)) {
                    return;
                }
                const option = document.createElement('option');
                option.value = optionDisplayValue(item) || name;
                option.dataset.stockId = id;
                option.dataset.title = name;
                option.dataset.brand = String(item.brand || '');
                option.dataset.description = item.code ? `Kod: ${item.code}` : '';
                option.dataset.unitPrice = String(item.list_price ?? 0);
                option.dataset.vatRate = String(item.vat_rate ?? 20);
                option.dataset.currency = String(item.currency || 'TRY');
                stockList.appendChild(option);
                existingIds.add(id);
            });
        };
        const stockOptionFor = (input) => {
            if (!stockList || !input) {
                return null;
            }
            const value = String(input.value || '').trim();
            if (!value) {
                return null;
            }

            return Array.from(stockList.options).find((option) => {
                return option.value === value || option.dataset.title === value;
            }) || null;
        };
        const applyStockSelection = (input) => {
            const option = stockOptionFor(input);
            const row = input.closest('[data-offer-line]');
            if (!row) {
                return false;
            }

            const stockInput = row.querySelector('[data-stock-item-id]');
            if (!option) {
                if (stockInput) {
                    stockInput.value = '';
                }
                return false;
            }

            if (stockInput) {
                stockInput.value = option.dataset.stockId || '';
            }
            input.value = option.dataset.title || input.value;

            const brandInput = row.querySelector('[data-stock-brand]');
            if (brandInput) {
                brandInput.value = option.dataset.brand || '';
            }
            const descriptionInput = row.querySelector('[data-stock-description]');
            if (descriptionInput && (!descriptionInput.value.trim() || descriptionInput.dataset.fromStock === '1')) {
                descriptionInput.value = option.dataset.description || '';
                descriptionInput.dataset.fromStock = '1';
            }
            const unitInput = row.querySelector('[data-offer-unit]');
            if (unitInput) {
                unitInput.value = Number(option.dataset.unitPrice || 0).toFixed(2);
            }
            const vatInput = row.querySelector('[data-offer-vat-rate]');
            if (vatInput) {
                vatInput.value = Number(option.dataset.vatRate || 20).toFixed(2);
            }
            if (currencySelect && option.dataset.currency) {
                currencySelect.value = option.dataset.currency;
            }

            return true;
        };
        const searchStockItems = (input) => {
            if (!stockSearchUrl) {
                return;
            }
            const query = String(input.value || '').trim();
            if (query.length < 2) {
                return;
            }
            if (stockCache.has(query)) {
                appendStockOptions(stockCache.get(query));
                applyStockSelection(input);
                return;
            }

            clearTimeout(stockSearchTimer);
            stockSearchTimer = window.setTimeout(() => {
                const url = `${stockSearchUrl}?q=${encodeURIComponent(query)}`;
                fetch(url, { headers: { Accept: 'application/json' } })
                    .then((response) => response.ok ? response.json() : null)
                    .then((payload) => {
                        const items = payload?.ok && Array.isArray(payload.data) ? payload.data : [];
                        stockCache.set(query, items);
                        appendStockOptions(items);
                        applyStockSelection(input);
                    })
                    .catch(() => {});
            }, 220);
        };
        const reindexRows = () => {
            rows().forEach((row, index) => {
                row.querySelectorAll('[name]').forEach((input) => {
                    input.name = input.name.replace(/items\[[^\]]+\]/, `items[${index}]`);
                });
            });
        };
        const updateRemoveButtons = () => {
            const count = rows().length;
            rows().forEach((row) => {
                const remove = row.querySelector('[data-offer-remove-line]');
                if (remove) {
                    remove.disabled = count <= 1;
                }
            });
        };
        const syncTotals = () => {
            let subtotal = 0;
            let vatTotal = 0;
            let total = 0;
            rows().forEach((row) => {
                const quantity = Math.max(0, numberValue(row.querySelector('[data-offer-qty]'), 1));
                const unit = Math.max(0, numberValue(row.querySelector('[data-offer-unit]'), 0));
                const vatRate = Math.max(0, numberValue(row.querySelector('[data-offer-vat-rate]'), 0));
                const lineSubtotal = quantity * unit;
                const lineVat = lineSubtotal * (vatRate / 100);
                const lineTotal = lineSubtotal + lineVat;
                subtotal += lineSubtotal;
                vatTotal += lineVat;
                total += lineTotal;

                const subtotalPreview = row.querySelector('[data-offer-line-subtotal]');
                const totalPreview = row.querySelector('[data-offer-line-total]');
                if (subtotalPreview) {
                    subtotalPreview.textContent = formatter().format(lineSubtotal);
                }
                if (totalPreview) {
                    totalPreview.textContent = formatter().format(lineTotal);
                }
            });

            if (subtotalOutput) {
                subtotalOutput.textContent = formatter().format(subtotal);
            }
            if (vatOutput) {
                vatOutput.textContent = formatter().format(vatTotal);
            }
            if (totalOutput) {
                totalOutput.textContent = formatter().format(total);
            }
            updateRemoveButtons();
        };

        addButton?.addEventListener('click', () => {
            if (!list || !template) {
                return;
            }
            const index = rows().length;
            const wrapper = document.createElement('div');
            wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(index)).trim();
            const row = wrapper.firstElementChild;
            if (row) {
                list.appendChild(row);
                reindexRows();
                syncTotals();
            }
        });

        offerForm.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const remove = target?.closest('[data-offer-remove-line]');
            if (!remove) {
                return;
            }
            const row = remove.closest('[data-offer-line]');
            if (row && rows().length > 1) {
                row.remove();
                reindexRows();
                syncTotals();
            }
        });
        offerForm.addEventListener('input', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (target?.matches('[data-stock-title]')) {
                searchStockItems(target);
                applyStockSelection(target);
            }
            syncTotals();
        });
        offerForm.addEventListener('change', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (target?.matches('[data-stock-title]')) {
                applyStockSelection(target);
            }
            syncTotals();
        });
        reindexRows();
        syncTotals();
    });

    document.querySelectorAll('[data-supplier-quote-card]').forEach((quoteCard) => {
        const currencySelect = quoteCard.querySelector('[data-supplier-price-currency]');
        const quantity = Math.max(0, Number(String(quoteCard.dataset.quantity || '1').replace(',', '.')) || 1);
        const quantityFormatter = new Intl.NumberFormat('tr-TR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        const numberValue = (input) => {
            const value = String(input?.value || '').replace(',', '.');
            const parsed = Number(value);

            return Number.isFinite(parsed) ? parsed : 0;
        };
        const moneyFormatter = () => new Intl.NumberFormat('tr-TR', {
            style: 'currency',
            currency: currencySelect?.value || 'TRY',
        });

        const syncSupplierQuotePrices = () => {
            const currency = currencySelect?.value || 'TRY';
            quoteCard.querySelectorAll('[data-supplier-currency-label]').forEach((label) => {
                label.textContent = currency;
            });
            quoteCard.querySelectorAll('[data-supplier-unit-price]').forEach((input) => {
                input.placeholder = 'Birim fiyat';
                const preview = input.closest('.supplier-price-label')?.querySelector('[data-supplier-total-preview]');
                const unitPrice = Math.max(0, numberValue(input));
                preview.textContent = unitPrice > 0
                    ? `Toplam: ${moneyFormatter().format(unitPrice * quantity)} (${quantityFormatter.format(quantity)} adet)`
                    : 'Toplam: -';
            });
        };

        quoteCard.addEventListener('input', syncSupplierQuotePrices);
        quoteCard.addEventListener('change', syncSupplierQuotePrices);
        syncSupplierQuotePrices();
    });

    const formatDateTR = (date) => date.toLocaleDateString('tr-TR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });

    const addPeriod = (startDate, count, unit) => {
        const next = new Date(startDate.getTime());
        if (unit === 'year') {
            next.setFullYear(next.getFullYear() + count);
        } else if (unit === 'month') {
            next.setMonth(next.getMonth() + count);
        } else {
            next.setDate(next.getDate() + count);
        }

        return next;
    };

    const periodPreviewText = (startValue, count, unit) => {
        if (!startValue || !count || !unit) {
            return '';
        }

        const start = new Date(`${startValue}T00:00:00`);
        if (Number.isNaN(start.getTime())) {
            return '';
        }

        const end = addPeriod(start, count, unit);
        const prefix = unit === 'year' ? 'Yıllık fatura dönemi' : 'Fatura dönemi';
        const years = unit === 'year' ? ` (${start.getFullYear()} / ${end.getFullYear()})` : '';

        return `${prefix}: ${formatDateTR(start)} - ${formatDateTR(end)}${years}`;
    };

    const renewalPeriodSelect = document.querySelector('[data-renewal-period-select]');
    const renewalStartDate = document.querySelector('[data-renewal-start-date]');
    const invoicePeriodPreview = document.querySelector('[data-invoice-period-preview]');
    if (renewalPeriodSelect && renewalStartDate && invoicePeriodPreview) {
        const syncInvoicePeriodPreview = () => {
            const selected = renewalPeriodSelect.selectedOptions[0];
            invoicePeriodPreview.textContent = periodPreviewText(
                renewalStartDate.value,
                Number(selected?.dataset.count || '0'),
                selected?.dataset.unit || ''
            );
        };

        renewalPeriodSelect.addEventListener('change', syncInvoicePeriodPreview);
        renewalStartDate.addEventListener('change', syncInvoicePeriodPreview);
        syncInvoicePeriodPreview();
    }

    const paymentMethodSelect = document.querySelector('[data-payment-method-select]');
    const paymentCustomerChoice = document.querySelector('[data-payment-customer-choice]');
    if (paymentMethodSelect && paymentCustomerChoice) {
        paymentMethodSelect.addEventListener('change', () => {
            if (paymentMethodSelect.value) {
                paymentCustomerChoice.checked = false;
            }
        });

        paymentCustomerChoice.addEventListener('change', () => {
            if (paymentCustomerChoice.checked) {
                paymentMethodSelect.value = '';
            }
        });
    }

    const publicPaymentForm = document.querySelector('[data-public-payment-form]');
    if (publicPaymentForm) {
        const methodSelect = publicPaymentForm.querySelector('[data-public-payment-method]');
        const bankPanel = publicPaymentForm.querySelector('[data-bank-transfer-panel]');
        const receiptInput = publicPaymentForm.querySelector('[data-bank-transfer-receipt]');
        const otherPanel = publicPaymentForm.querySelector('[data-other-payment-panel]');
        const otherInput = publicPaymentForm.querySelector('[data-other-payment-input]');
        const isBankTransfer = (value) => {
            const normalized = String(value || '').toLocaleLowerCase('tr-TR');

            return normalized.includes('havale') || normalized.includes('eft');
        };
        const isOtherPayment = (value) => value === '__other_payment_terms__';
        const syncPaymentPanels = () => {
            const value = methodSelect?.value;
            const otherActive = isOtherPayment(value);
            const bankActive = isBankTransfer(value) && !otherActive;
            if (bankPanel) {
                bankPanel.hidden = !bankActive;
            }
            if (receiptInput) {
                receiptInput.required = bankActive;
            }
            if (otherPanel) {
                otherPanel.hidden = !otherActive;
            }
            if (otherInput) {
                otherInput.required = otherActive;
            }
        };

        methodSelect?.addEventListener('change', syncPaymentPanels);
        syncPaymentPanels();
    }

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[character]));

    const normalizeSearchText = (value) => String(value || '')
        .toLocaleLowerCase('tr-TR')
        .replaceAll('ı', 'i')
        .replaceAll('ğ', 'g')
        .replaceAll('ü', 'u')
        .replaceAll('ş', 's')
        .replaceAll('ö', 'o')
        .replaceAll('ç', 'c')
        .replaceAll('İ', 'i')
        .replaceAll('Ğ', 'g')
        .replaceAll('Ü', 'u')
        .replaceAll('Ş', 's')
        .replaceAll('Ö', 'o')
        .replaceAll('Ç', 'c')
        .trim();

    const offerCustomerSource = document.getElementById('offer-builder-customers-json');
    let offerCustomers = [];
    try {
        offerCustomers = offerCustomerSource?.textContent ? JSON.parse(offerCustomerSource.textContent) : [];
    } catch (error) {
        offerCustomers = [];
    }

    document.querySelectorAll('[data-offer-builder-form]').forEach((form) => {
        const input = form.querySelector('[data-offer-customer-input]');
        const customerId = form.querySelector('[data-offer-customer-id]');
        const email = form.querySelector('[data-offer-customer-email]');
        const phone = form.querySelector('[data-offer-customer-phone]');
        const results = form.querySelector('[data-offer-customer-results]');

        if (!input || !results) {
            return;
        }

        const customerContacts = (customer) => Array.isArray(customer?.contacts) ? customer.contacts : [];
        const preferredContactValue = (customer, key) => {
            const contacts = customerContacts(customer);
            const preferred = contacts.find((contact) => contact.notify && String(contact[key] || '').trim() !== '')
                || contacts.find((contact) => String(contact[key] || '').trim() !== '');

            return preferred ? String(preferred[key] || '').trim() : '';
        };
        const closeResults = () => {
            results.hidden = true;
            results.innerHTML = '';
        };
        const customerSearchValue = (customer) => normalizeSearchText([
            customer.name,
            customer.email,
            customer.phone,
            customer.tax,
            ...customerContacts(customer).flatMap((contact) => [
                contact.name,
                contact.email,
                contact.phone,
            ]),
        ].join(' '));
        const selectCustomer = (customer) => {
            const customerName = String(customer.name || '');
            const nextEmail = String(customer.email || '').trim() || preferredContactValue(customer, 'email');
            const nextPhone = String(customer.phone || '').trim() || preferredContactValue(customer, 'phone');

            input.value = customerName;
            input.dataset.selectedCustomerId = String(customer.id || '');
            if (customerId) {
                customerId.value = String(customer.id || '');
            }
            if (email) {
                email.value = nextEmail;
            }
            if (phone) {
                phone.value = nextPhone;
            }
            input.dispatchEvent(new Event('change', { bubbles: true }));
            closeResults();
        };
        const renderResults = () => {
            const query = normalizeSearchText(input.value);
            results.innerHTML = '';

            if (input.dataset.selectedCustomerId && query !== '' && offerCustomers.some((customer) => String(customer.id || '') === input.dataset.selectedCustomerId && normalizeSearchText(customer.name || '') === query)) {
                closeResults();
                return;
            }

            if (!Array.isArray(offerCustomers) || offerCustomers.length < 1) {
                results.innerHTML = '<div class="offer-customer-empty">Kayıtlı cari bulunamadı. Manuel yazabilirsiniz.</div>';
                results.hidden = false;
                return;
            }

            const matches = offerCustomers
                .filter((customer) => query === '' || customerSearchValue(customer).includes(query))
                .slice(0, 10);

            if (matches.length < 1) {
                results.innerHTML = '<div class="offer-customer-empty">Cari bulunamadı. Manuel yazmaya devam edebilirsiniz.</div>';
                results.hidden = false;
                return;
            }

            matches.forEach((customer) => {
                const contacts = customerContacts(customer);
                const meta = [
                    customer.email || preferredContactValue(customer, 'email') || customer.phone || customer.tax || 'Cari',
                    contacts.length > 0 ? `${contacts.length} yetkili` : '',
                ].filter(Boolean).join(' · ');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'offer-customer-result';
                button.innerHTML = `
                    <strong>${escapeHtml(customer.name || '-')}</strong>
                    <span>${escapeHtml(meta)}</span>
                `;
                button.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    selectCustomer(customer);
                });
                button.addEventListener('click', () => selectCustomer(customer));
                results.appendChild(button);
            });
            results.hidden = false;
        };

        input.addEventListener('input', () => {
            input.dataset.selectedCustomerId = '';
            if (customerId) {
                customerId.value = '';
            }
            renderResults();
        });
        input.addEventListener('focus', renderResults);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeResults();
            }
        });
        document.addEventListener('click', (event) => {
            if (!form.contains(event.target)) {
                closeResults();
            }
        });
    });

    document.querySelectorAll('[data-manual-payment-form]').forEach((form) => {
        const source = document.getElementById('manual-payment-customers-json');
        let customers = [];
        try {
            customers = source?.textContent ? JSON.parse(source.textContent) : [];
        } catch (error) {
            customers = [];
        }

        const input = form.querySelector('[data-manual-customer-input]');
        const customerId = form.querySelector('[data-manual-customer-id]');
        const email = form.querySelector('[data-manual-customer-email]');
        const phone = form.querySelector('[data-manual-customer-phone]');
        const tax = form.querySelector('[data-manual-customer-tax]');
        const results = form.querySelector('[data-manual-customer-results]');
        const contactPanel = form.querySelector('[data-manual-contact-panel]');
        const contactList = form.querySelector('[data-manual-contact-list]');
        const contactCount = form.querySelector('[data-manual-contact-count]');

        if (!input || !results || !customerId || !contactPanel || !contactList) {
            return;
        }

        const renderContacts = (customer) => {
            const contacts = Array.isArray(customer?.contacts) ? customer.contacts : [];
            contactList.innerHTML = '';

            if (contacts.length < 1) {
                contactPanel.hidden = false;
                contactList.innerHTML = '<div class="empty compact">Bu caride e-posta veya telefon bilgisi olan yetkili yok.</div>';
                if (contactCount) {
                    contactCount.textContent = '0 yetkili';
                }
                return;
            }

            contacts.forEach((contact) => {
                const id = Number(contact.id || 0);
                const name = String(contact.name || 'Yetkili');
                const contactEmail = String(contact.email || '');
                const contactPhone = String(contact.phone || '');
                const checked = contact.notify ? 'checked' : '';
                const badge = contact.notify ? '<span class="manual-payment-contact-badge">Bilgilendirme açık</span>' : '';
                contactList.insertAdjacentHTML('beforeend', `
                    <label class="manual-payment-contact-option">
                        <input type="checkbox" name="selected_contact_ids[]" value="${id}" ${checked}>
                        <span class="manual-payment-contact-info">
                            <strong>${escapeHtml(name)}</strong>
                            <em>${escapeHtml(contactEmail || contactPhone || '-')}</em>
                        </span>
                        ${badge}
                    </label>
                `);
            });

            contactPanel.hidden = false;
            if (contactCount) {
                contactCount.textContent = `${contacts.length} yetkili`;
            }
        };

        const selectCustomer = (customer) => {
            input.value = String(customer.name || '');
            customerId.value = String(customer.id || '');
            if (email && !email.value) {
                email.value = String(customer.email || '');
            }
            if (phone && !phone.value) {
                phone.value = String(customer.phone || '');
            }
            if (tax && !tax.value) {
                tax.value = String(customer.tax || '');
            }
            results.hidden = true;
            renderContacts(customer);
        };

        const renderResults = () => {
            const query = normalizeSearchText(input.value);
            results.innerHTML = '';

            if (query === '') {
                results.hidden = true;
                return;
            }

            const matches = customers
                .filter((customer) => {
                    const haystack = normalizeSearchText([
                        customer.name,
                        customer.email,
                        customer.phone,
                        customer.tax,
                    ].join(' '));

                    return haystack.includes(query);
                })
                .slice(0, 8);

            if (matches.length < 1) {
                results.innerHTML = '<div class="manual-payment-customer-empty">Cari bulunamadı. Manuel yazmaya devam edebilirsiniz.</div>';
                results.hidden = false;
                return;
            }

            matches.forEach((customer) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'manual-payment-customer-result';
                button.innerHTML = `
                    <strong>${escapeHtml(customer.name || '-')}</strong>
                    <span>${escapeHtml(customer.email || customer.phone || customer.tax || 'Cari')}</span>
                `;
                button.addEventListener('click', () => selectCustomer(customer));
                results.appendChild(button);
            });
            results.hidden = false;
        };

        input.addEventListener('input', () => {
            customerId.value = '';
            contactPanel.hidden = true;
            contactList.innerHTML = '';
            renderResults();
        });

        input.addEventListener('focus', renderResults);

        document.addEventListener('click', (event) => {
            if (!form.contains(event.target)) {
                results.hidden = true;
            }
        });
    });

    const supplierLinkCard = (link) => {
        const url = String(link?.url || '');
        const label = String(link?.label || 'Tedarikçi');
        const createdAt = String(link?.created_at || '');
        const mailStatus = String(link?.mail_status || '');
        const mailStatusType = String(link?.mail_status_type || 'info').replace(/[^a-z0-9_-]/gi, '');

        return `
            <div class="supplier-link-card">
                <div>
                    <strong>${escapeHtml(label)}</strong>
                    <span>${escapeHtml(createdAt)}</span>
                    ${mailStatus ? `<span class="supplier-link-mail-status ${escapeHtml(mailStatusType)}">${escapeHtml(mailStatus)}</span>` : ''}
                </div>
                <input readonly value="${escapeHtml(url)}" aria-label="Tedarikçi teklif linki">
                <div class="inline-actions">
                    <button type="button" class="button small secondary" data-copy-value="${escapeHtml(url)}">Linki kopyala</button>
                    <a class="button small primary" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">Formu aç</a>
                </div>
            </div>
        `;
    };

    document.querySelectorAll('[data-supplier-link-form]').forEach((form) => {
        const status = form.querySelector('[data-supplier-link-status]');
        const submitButton = form.querySelector('button[type="submit"]');
        const sidePanel = form.closest('.supplier-price-side');
        const results = sidePanel?.querySelector('[data-supplier-link-results]');
        const list = sidePanel?.querySelector('[data-supplier-link-list]');

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!list || !results) {
                form.submit();
                return;
            }

            const previousText = submitButton?.textContent || 'Teklif linki oluştur';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Link oluşturuluyor...';
            }
            if (status) {
                status.hidden = false;
                status.textContent = 'Tedarikçi teklif linki hazırlanıyor.';
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json().catch(() => ({ ok: false, message: 'Sunucu cevabı okunamadı.' }));
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || 'Teklif linki oluşturulamadı.');
                }

                const cards = (payload.links || []).map(supplierLinkCard).join('');
                if (cards) {
                    list.insertAdjacentHTML('afterbegin', cards);
                    results.hidden = false;
                }
                if (status) {
                    status.textContent = payload.message || 'Teklif linki oluşturuldu.';
                }
            } catch (error) {
                if (status) {
                    status.hidden = false;
                    status.textContent = error.message || 'Teklif linki oluşturulamadı.';
                }
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = previousText;
                }
            }
        });
    });

    document.addEventListener('click', (event) => {
        const copyButton = event.target.closest('[data-copy-value]');
        if (copyButton) {
            const value = copyButton.dataset.copyValue || '';
            if (value && navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(value).then(() => {
                    const previous = copyButton.textContent;
                    copyButton.textContent = 'Kopyalandı';
                    window.setTimeout(() => {
                        copyButton.textContent = previous || 'Linki kopyala';
                    }, 1600);
                }).catch(() => {
                    copyButton.textContent = 'Kopyalanamadı';
                });
            }
            return;
        }

        const opener = event.target.closest('[data-dialog-open]');
        if (opener) {
            const dialog = document.getElementById(opener.dataset.dialogOpen || '');
            if (dialog?.showModal) {
                dialog.showModal();
            }
            return;
        }

        if (event.target.matches('[data-dialog-close]')) {
            event.target.closest('dialog')?.close();
            return;
        }

        if (event.target.matches('dialog.decision-dialog, dialog.app-dialog')) {
            event.target.close();
        }
    });

    document.querySelectorAll('dialog[data-auto-open-dialog]').forEach((dialog) => {
        if (dialog.showModal && !dialog.open) {
            dialog.showModal();
        }
    });

    const openDefinitionHash = () => {
        if (!window.location.hash) {
            return;
        }

        let target = null;
        try {
            target = document.querySelector(window.location.hash);
        } catch (error) {
            return;
        }

        const details = target?.matches?.('.definition-card-details')
            ? target
            : target?.querySelector?.('.definition-card-details');
        if (details) {
            details.open = true;
        }
    };

    openDefinitionHash();
    window.addEventListener('hashchange', openDefinitionHash);

    document.querySelectorAll('[data-postpone-form]').forEach((form) => {
        const quickValue = form.querySelector('[data-postpone-quick-value]');
        const dateInput = form.querySelector('[data-postpone-date]');
        const formatDateInput = (date) => {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');

            return `${year}-${month}-${day}`;
        };
        const addMonths = (count) => {
            const date = new Date();
            date.setHours(0, 0, 0, 0);
            date.setMonth(date.getMonth() + count);

            return date;
        };

        form.addEventListener('click', (event) => {
            const button = event.target.closest('[data-postpone-quick]');
            if (!button || !dateInput || !quickValue) {
                return;
            }

            const value = button.dataset.postponeQuick || '';
            quickValue.value = value;
            if (value === '1m') {
                dateInput.value = formatDateInput(addMonths(1));
            } else if (value === '2m') {
                dateInput.value = formatDateInput(addMonths(2));
            } else if (value === '3m') {
                dateInput.value = formatDateInput(addMonths(3));
            } else if (value === 'year_end') {
                dateInput.value = `${new Date().getFullYear()}-12-31`;
            }
        });

        dateInput?.addEventListener('input', () => {
            if (quickValue) {
                quickValue.value = '';
            }
        });
    });

    const renewForm = document.querySelector('[data-renew-form]');
    if (renewForm) {
        const preview = renewForm.querySelector('[data-renew-period-preview]');
        if (preview && renewForm.dataset.startDate && renewForm.dataset.endDate) {
            const start = new Date(`${renewForm.dataset.startDate}T00:00:00`);
            const end = new Date(`${renewForm.dataset.endDate}T00:00:00`);
            if (!Number.isNaN(start.getTime()) && !Number.isNaN(end.getTime())) {
                preview.textContent = `Yeni dönem: ${formatDateTR(start)} - ${formatDateTR(end)}`;
            }
        }
    }

    const mailDriverSelect = document.querySelector('[data-mail-driver-select]');
    if (mailDriverSelect) {
        const mailSections = Array.from(document.querySelectorAll('[data-mail-settings]'));
        const mailDriverBadge = document.querySelector('[data-mail-driver-badge]');
        const driverLabels = {
            log: 'Log',
            smtp: 'SMTP',
            microsoft365: 'Microsoft 365',
            mail: 'PHP mail',
        };

        const syncMailSections = () => {
            const selected = mailDriverSelect.value || 'log';
            mailSections.forEach((section) => {
                section.hidden = section.dataset.mailSettings !== selected;
            });
            if (mailDriverBadge) {
                mailDriverBadge.textContent = driverLabels[selected] || selected;
            }
        };

        mailDriverSelect.addEventListener('change', syncMailSections);
        syncMailSections();
    }

    const grapesEditorElement = document.querySelector('[data-grapesjs-editor]');
    const grapesForm = document.querySelector('[data-grapesjs-form]');
    if (grapesEditorElement && grapesForm) {
        const htmlInput = grapesForm.querySelector('[data-grapesjs-html]');
        const cssInput = grapesForm.querySelector('[data-grapesjs-css]');
        const projectInput = grapesForm.querySelector('[data-grapesjs-project]');
        const actionInput = grapesForm.querySelector('[data-grapesjs-action]');
        const fieldsContainer = grapesForm.querySelector('[data-grapesjs-fields]');
        const fieldCards = Array.from(grapesForm.querySelectorAll('[data-grapesjs-field]'));
        const fieldDefinitions = fieldCards.map((card) => ({
            key: card.dataset.key || '',
            label: card.dataset.label || '',
            token: card.dataset.token || '',
            description: card.dataset.description || '',
            content: card.dataset.content || '',
        })).filter((field) => field.key && field.token);
        const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[character]));
        const templateGuardCss = `
/* renewal-template-layout-guard:start */
.mail-document,
.mail-document * {
  box-sizing: border-box;
}
.mail-document {
  width: 100% !important;
  max-width: 740px !important;
  margin-left: auto !important;
  margin-right: auto !important;
  overflow: hidden !important;
  color-scheme: light only;
}
.mail-document .mail-card,
.mail-document .brand-row,
.mail-document .footer {
  width: 100% !important;
  max-width: 680px !important;
}
.mail-document .brand-row {
  margin-left: auto !important;
  margin-right: auto !important;
  text-align: center !important;
}
.mail-document img {
  max-width: 100%;
  height: auto;
}
.mail-document .brand-logo,
.mail-document [data-template-logo] {
  display: inline-block !important;
  width: auto !important;
  max-width: 170px !important;
  max-height: 62px !important;
  height: auto !important;
  object-fit: contain !important;
}
.mail-document h1,
.mail-document h2,
.mail-document h3,
.mail-document p,
.mail-document a,
.mail-document span,
.mail-document strong,
.mail-document td {
  max-width: 100%;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.mail-document .metric-row {
  width: 100% !important;
  border-collapse: separate !important;
  border-spacing: 0 !important;
  table-layout: fixed !important;
}
.mail-document .metric {
  width: 49% !important;
  min-width: 0 !important;
  vertical-align: top !important;
}
.mail-document .metric-gap {
  width: 2% !important;
  font-size: 0 !important;
  line-height: 0 !important;
}
.mail-document .metric + .metric {
  margin-top: 0 !important;
}
.mail-document .metric span {
  line-height: 1.2;
}
.mail-document .metric strong {
  line-height: 1.12;
}
.mail-document .metric-remaining {
  background: {{urgency_bg}} !important;
  border: 1px solid {{urgency_border}} !important;
}
.mail-document .metric-remaining span,
.mail-document .metric-remaining strong {
  color: {{urgency_text}} !important;
}
.mail-document .urgency-overdue,
.mail-document .metric-remaining.urgency-overdue {
  background: #7f0000 !important;
  border-color: #4c0000 !important;
}
.mail-document .urgency-overdue span,
.mail-document .urgency-overdue strong {
  color: #ffffff !important;
}
.mail-document table {
  max-width: 100%;
  table-layout: fixed;
}
@media screen and (max-width: 620px) {
  .mail-document {
    padding: 18px !important;
  }
  .mail-document .brand-logo,
  .mail-document [data-template-logo] {
    max-width: 130px !important;
    max-height: 52px !important;
  }
  .mail-document .card-inner {
    padding: 18px !important;
  }
  .mail-document .hero-card td {
    padding: 16px !important;
  }
  .mail-document h1 {
    font-size: 21px !important;
  }
  .mail-document p,
  .mail-document .lead {
    font-size: 14px !important;
  }
  .mail-document .metric-row {
    width: 100% !important;
    border-spacing: 0 !important;
    table-layout: fixed !important;
  }
  .mail-document .metric {
    width: 49% !important;
    padding: 10px !important;
  }
  .mail-document .metric-gap {
    width: 2% !important;
    font-size: 0 !important;
    line-height: 0 !important;
  }
  .mail-document .metric span {
    font-size: 10px !important;
    line-height: 1.2 !important;
  }
  .mail-document .metric strong {
    font-size: 18px !important;
    line-height: 1.1 !important;
  }
  .mail-document .metric + .metric {
    margin-top: 0 !important;
  }
}
/* renewal-template-layout-guard:end */`.trim();

        if (window.grapesjs && htmlInput && cssInput && projectInput) {
            const editor = window.grapesjs.init({
                container: grapesEditorElement,
                height: '720px',
                width: 'auto',
                fromElement: false,
                storageManager: false,
                components: htmlInput.value,
                style: cssInput.value,
                selectorManager: { componentFirst: true },
                deviceManager: {
                    devices: [
                        { name: 'Desktop', width: '' },
                        { name: 'Mobil', width: '360px', widthMedia: '480px' },
                    ],
                },
            });
            let guardStyleApplied = cssInput.value.includes('renewal-template-layout-guard');
            const applyTemplateGuards = (includeCss = true) => {
                if (includeCss && !guardStyleApplied) {
                    editor.addStyle(templateGuardCss);
                    guardStyleApplied = true;
                }

                const wrapper = editor.getWrapper();
                if (!wrapper) {
                    return;
                }

                wrapper.find('img').forEach((component) => {
                    const attributes = component.getAttributes();
                    const source = String(attributes.src || '');
                    if (!source.includes('{{logo_url}}') && !attributes['data-template-logo']) {
                        return;
                    }

                    component.addClass('brand-logo');
                    component.addAttributes({ 'data-template-logo': '1' });
                    component.setStyle({
                        ...component.getStyle(),
                        width: 'auto',
                        'max-width': '170px',
                        'max-height': '62px',
                        height: 'auto',
                        'object-fit': 'contain',
                        display: component.getStyle().display || 'block',
                        'margin-left': 'auto',
                        'margin-right': 'auto',
                    });
                });
            };

            const blocks = editor.BlockManager;
            const placeholderBlockIds = [];
            fieldDefinitions.forEach((field) => {
                const blockId = `placeholder-${field.key}`;
                placeholderBlockIds.push(blockId);
                blocks.add(blockId, {
                    label: `<div class="gjs-field-block"><strong>${escapeHtml(field.label)}</strong><code>${escapeHtml(field.token)}</code><small>${escapeHtml(field.description)}</small></div>`,
                    category: 'Alanlar',
                    content: field.content || `<span data-placeholder="${escapeHtml(field.key)}">${escapeHtml(field.token)}</span>`,
                    attributes: { title: field.description },
                });
            });
            blocks.add('mail-title', {
                label: 'Baslik',
                category: 'Mail',
                content: '<h1>{{title}}</h1>',
            });
            blocks.add('mail-text', {
                label: 'Metin',
                category: 'Mail',
                content: '<p>Merhaba {{contact_name}},</p>',
            });
            blocks.add('mail-metric', {
                label: 'Kalan süre',
                category: 'Mail',
                content: '<table class="metric-row" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;margin:18px 0;border-collapse:separate;border-spacing:0;table-layout:fixed;"><tr><td class="metric metric-remaining {{urgency_class}}" width="49%" bgcolor="{{urgency_bg}}" style="width:49% !important;padding:16px;background:{{urgency_bg}};border:1px solid {{urgency_border}};border-radius:8px;vertical-align:top;"><span style="display:block;color:{{urgency_text}};font-size:11px;font-weight:700;text-transform:uppercase;line-height:1.2;">Kalan süre</span><strong style="display:block;margin-top:7px;color:{{urgency_text}};font-size:26px;line-height:1.08;word-break:break-word;">{{remaining_days}}</strong></td><td class="metric-gap" width="2%" style="width:2%;font-size:0;line-height:0;">&nbsp;</td><td class="metric metric-date" width="49%" bgcolor="#eef6fb" style="width:49% !important;padding:16px;background:#eef6fb;border:1px solid #d5e7ef;border-radius:8px;vertical-align:top;"><span style="display:block;color:#66756f;font-size:11px;font-weight:700;text-transform:uppercase;line-height:1.2;">Yenileme tarihi</span><strong style="display:block;margin-top:7px;color:#0f625b;font-size:26px;line-height:1.08;word-break:break-word;">{{renewal_date}}</strong></td></tr></table>',
            });
            blocks.add('mail-table', {
                label: 'Bilgi tablo',
                category: 'Mail',
                content: '<table><tr><td>Yenileme tarihi</td><td>{{renewal_date}}</td></tr><tr><td>Bir Önceki Fatura Numarası</td><td>{{previous_invoice_number}}</td></tr><tr><td>Marka</td><td>{{brand}}</td></tr></table>',
            });
            blocks.add('mail-button', {
                label: 'Buton',
                category: 'Mail',
                content: '<a href="#" style="display:inline-block;padding:12px 18px;background:#147c72;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Buton</a>',
            });

            if (fieldsContainer && !fieldsContainer.hidden && placeholderBlockIds.length) {
                const placeholderBlocks = placeholderBlockIds.map((id) => blocks.get(id)).filter(Boolean);
                fieldsContainer.replaceChildren(blocks.render(placeholderBlocks, { external: true }));
            }

            grapesForm.addEventListener('click', (event) => {
                const submitter = event.target.closest('[data-grapesjs-submit-action]');
                if (submitter && actionInput) {
                    actionInput.value = submitter.dataset.grapesjsSubmitAction || 'save_template';
                }
            });

            const loadCanonicalTemplate = () => {
                if (htmlInput.value.trim()) {
                    editor.setComponents(htmlInput.value);
                }
                if (cssInput.value.trim()) {
                    editor.setStyle(cssInput.value);
                }
                guardStyleApplied = false;
                applyTemplateGuards();
            };

            if (projectInput.value.trim()) {
                try {
                    editor.loadProjectData(JSON.parse(projectInput.value));
                    loadCanonicalTemplate();
                } catch (error) {
                    projectInput.value = '';
                    loadCanonicalTemplate();
                }
            } else {
                loadCanonicalTemplate();
            }

            grapesForm.addEventListener('submit', () => {
                applyTemplateGuards(false);
                htmlInput.value = editor.getHtml();
                cssInput.value = editor.getCss();
                projectInput.value = JSON.stringify(editor.getProjectData());
            });
        } else {
            grapesEditorElement.innerHTML = '<div class="empty">GrapesJS kütüphanesi yüklenemedi. İnternet bağlantısını kontrol edin.</div>';
        }
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const pushBadge = document.querySelector('[data-push-badge]');
    const pushStatus = document.querySelector('[data-push-status]');
    const pushSubscribeButton = document.querySelector('[data-push-subscribe]');
    const pushTestButton = document.querySelector('[data-push-test]');

    const setPushStatus = (title, message, active = false) => {
        if (pushStatus) {
            pushStatus.innerHTML = `<strong>${title}</strong><span>${message}</span>`;
        }
        if (pushBadge) {
            pushBadge.textContent = active ? 'Açık' : 'Kapalı';
            pushBadge.classList.toggle('active', active);
            pushBadge.classList.toggle('cancelled', !active);
        }
    };

    const base64UrlToUint8Array = (value) => {
        const padding = '='.repeat((4 - value.length % 4) % 4);
        const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = window.atob(base64);
        const output = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i += 1) {
            output[i] = raw.charCodeAt(i);
        }
        return output;
    };

    const bufferSourceToBase64Url = (value) => {
        const bytes = new Uint8Array(value);
        let raw = '';
        bytes.forEach((byte) => {
            raw += String.fromCharCode(byte);
        });

        return window.btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };

    const pushPost = (url, payload = {}) => fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify(payload),
    }).then(async (response) => {
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.ok === false) {
            throw new Error(data.message || 'Web push işlemi tamamlanamadı.');
        }

        return data;
    });

    const initPwa = async () => {
        if ((pushSubscribeButton || pushTestButton) && !window.isSecureContext) {
            setPushStatus('HTTPS gerekli', 'Web push için domainin geçerli SSL sertifikası ile açılması gerekiyor.');
            pushSubscribeButton && (pushSubscribeButton.disabled = true);
            pushTestButton && (pushTestButton.disabled = true);
            return null;
        }

        if (!('serviceWorker' in navigator)) {
            setPushStatus('Desteklenmiyor', 'Bu tarayıcı PWA bildirimlerini desteklemiyor.');
            return null;
        }

        const registration = await navigator.serviceWorker.register('/service-worker.js');

        if (!pushSubscribeButton && !pushTestButton) {
            return registration;
        }

        if (!('PushManager' in window) || !('Notification' in window)) {
            setPushStatus('Desteklenmiyor', 'Bu tarayıcı web push bildirimlerini desteklemiyor.');
            return registration;
        }

        const keyResponse = await fetch('/api/push/public-key').then((response) => response.json());
        if (!keyResponse.ok || !keyResponse.publicKey) {
            throw new Error(keyResponse.message || 'Web push anahtarı alınamadı.');
        }

        let existing = await registration.pushManager.getSubscription();
        const currentKey = existing?.options?.applicationServerKey
            ? bufferSourceToBase64Url(existing.options.applicationServerKey)
            : '';
        if (existing && currentKey && currentKey !== keyResponse.publicKey) {
            const oldPayload = existing.toJSON();
            await existing.unsubscribe();
            await pushPost('/api/push/unsubscribe', oldPayload).catch(() => null);
            existing = null;
        }

        setPushStatus(
            existing ? 'Bildirimler açık' : 'Bildirimler kapalı',
            existing ? 'Bu cihaz yenileme bildirimlerini alacak.' : 'Bu cihazda bildirim almak için izin verin.',
            Boolean(existing)
        );

        return registration;
    };

    const pwaRegistrationPromise = initPwa().catch(() => {
        setPushStatus('Hazır değil', 'PWA bildirimi başlatılamadı.');
        return null;
    });

    pushSubscribeButton?.addEventListener('click', async () => {
        try {
            const registration = await pwaRegistrationPromise;
            if (!registration) {
                return;
            }

            setPushStatus('Hazırlanıyor', 'Tarayıcı bildirimi için izin isteniyor.');
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                setPushStatus('İzin verilmedi', 'Tarayıcı bildirim izni verilmedi.');
                return;
            }

            const keyResponse = await fetch('/api/push/public-key').then((response) => response.json());
            if (!keyResponse.ok || !keyResponse.publicKey) {
                throw new Error(keyResponse.message || 'Web push anahtarı alınamadı.');
            }

            const existing = await registration.pushManager.getSubscription();
            if (existing) {
                const oldPayload = existing.toJSON();
                await existing.unsubscribe();
                await pushPost('/api/push/unsubscribe', oldPayload).catch(() => null);
            }

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64UrlToUint8Array(keyResponse.publicKey),
            });

            const payload = subscription.toJSON();
            payload.contentEncoding = (PushManager.supportedContentEncodings || ['aes128gcm'])[0];
            await pushPost('/api/push/subscribe', payload);
            setPushStatus('Bildirimler açık', 'Bu cihaz yenileme bildirimlerini alacak.', true);
        } catch (error) {
            setPushStatus('Bildirim açılamadı', error.message || 'Tarayıcı aboneliği kaydedilemedi.');
        }
    });

    pushTestButton?.addEventListener('click', async () => {
        try {
            const result = await pushPost('/api/push/test');
            setPushStatus(
                'Test gönderildi',
                result.message || 'Birkaç saniye içinde tarayıcı bildirimi gelmeli.',
                true
            );
        } catch (error) {
            setPushStatus('Test gönderilemedi', error.message || 'Aktif web push aboneliği bulunamadı.');
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
            return;
        }

        if (form.dataset.submitLocked === '1') {
            event.preventDefault();
            return;
        }

        const buttons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
        if (!buttons.length) {
            return;
        }

        const submitter = buttons.includes(event.submitter) ? event.submitter : buttons[0];
        const originalLabel = submitter.tagName === 'INPUT' ? submitter.value : submitter.textContent;
        let remaining = 5;

        if (submitter.name && submitter.value) {
            const intent = document.createElement('input');
            intent.type = 'hidden';
            intent.name = submitter.name;
            intent.value = submitter.value;
            intent.dataset.submitIntent = '1';
            form.appendChild(intent);
        }

        const setSubmitterLabel = () => {
            const label = `Bekleyin (${remaining})`;
            if (submitter.tagName === 'INPUT') {
                submitter.value = label;
            } else {
                submitter.textContent = label;
            }
        };

        form.dataset.submitLocked = '1';
        buttons.forEach((button) => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        });
        setSubmitterLabel();

        const timer = window.setInterval(() => {
            remaining -= 1;
            if (remaining > 0) {
                setSubmitterLabel();
                return;
            }

            window.clearInterval(timer);
            delete form.dataset.submitLocked;
            buttons.forEach((button) => {
                button.disabled = false;
                button.removeAttribute('aria-busy');
            });

            if (submitter.tagName === 'INPUT') {
                submitter.value = originalLabel;
            } else {
                submitter.textContent = originalLabel;
            }
        }, 1000);
    });

    const fillFirstContactRow = (form, contact) => {
        const contactEditor = form?.querySelector('[data-contact-editor]');
        let row = contactEditor?.querySelector('[data-contact-row]');
        if (!row) {
            row = addContactRow(contactEditor);
        }

        if (!row) {
            return;
        }

        const name = row.querySelector('[data-contact-name]');
        const email = row.querySelector('[data-contact-email]');
        const phone = row.querySelector('[data-contact-phone]');
        const notify = row.querySelector('[name$="[notify_enabled]"]');

        if (name && !name.value) {
            name.value = contact.contact_type === 'person' ? (contact.short_name || contact.name || '') : (contact.short_name || '');
        }
        if (email && !email.value) {
            email.value = contact.email || '';
        }
        if (phone && !phone.value) {
            phone.value = contact.phone || '';
        }
        if (notify && contact.email) {
            notify.checked = true;
        }
    };

    const initParasutSearch = (searchBox) => {
        const input = searchBox.querySelector('[data-parasut-query]');
        const results = searchBox.querySelector('[data-parasut-results]');
        if (!input || !results) {
            return;
        }

        const parasutType = searchBox.dataset.parasutType || 'customer';
        const resolveParasutForm = () => {
            const targetForm = searchBox.dataset.parasutTargetForm || '';
            if (targetForm) {
                const selector = `[data-parasut-form="${targetForm.replace(/"/g, '\\"')}"]`;
                const scoped = searchBox.parentElement?.querySelector(selector) || document.querySelector(selector);
                if (scoped instanceof HTMLFormElement) {
                    return scoped;
                }
            }

            const closest = searchBox.closest('form');
            if (closest instanceof HTMLFormElement) {
                return closest;
            }

            let sibling = searchBox.nextElementSibling;
            while (sibling) {
                if (sibling instanceof HTMLFormElement) {
                    return sibling;
                }

                const nested = sibling.querySelector?.('form');
                if (nested instanceof HTMLFormElement) {
                    return nested;
                }

                sibling = sibling.nextElementSibling;
            }

            return searchBox.parentElement?.querySelector('form.form-grid, form[data-parasut-form], form') || null;
        };
        const form = resolveParasutForm();
        let timer = null;

        const setField = (name, value) => {
            const field = form?.querySelector(`[name="${name}"]`);
            if (field) {
                field.value = value || '';
            }
        };

        const renderMessage = (message) => {
            results.replaceChildren();
            const item = document.createElement('div');
            item.className = 'suggestion-empty';
            item.textContent = message;
            results.appendChild(item);
        };

        const renderContacts = (contacts) => {
            results.replaceChildren();

            if (!contacts.length) {
                renderMessage('Eşleşen cari bulunamadı.');
                return;
            }

            contacts.forEach((contact) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'suggestion-item';

                const main = document.createElement('span');
                main.className = 'suggestion-main';
                main.textContent = contact.name || 'Isimsiz cari';

                const meta = document.createElement('span');
                meta.className = 'suggestion-meta';
                meta.textContent = [contact.account_type_label, contact.email, contact.phone, contact.city, contact.tax_number].filter(Boolean).join(' - ');

                button.append(main, meta);
                button.addEventListener('click', () => {
                    setField('parasut_contact_id', contact.id);
                    setField('company_name', contact.name);
                    setField('contact_name', contact.contact_type === 'person' ? (contact.short_name || contact.name) : contact.short_name);
                    setField('email', contact.email);
                    setField('phone', contact.phone);
                    setField('tax_office', contact.tax_office);
                    setField('tax_number', contact.tax_number);
                    setField('city', contact.city);
                    setField('district', contact.district);
                    setField('address', contact.address);
                    fillFirstContactRow(form, contact);
                    input.value = contact.name || '';
                    results.replaceChildren();
                });

                results.appendChild(button);
            });
        };

        input.addEventListener('input', () => {
            clearTimeout(timer);
            const query = input.value.trim();

            if (query.length < 2) {
                results.replaceChildren();
                return;
            }

            timer = setTimeout(async () => {
                renderMessage('Araniyor...');

                try {
                    const response = await fetch(`/api/parasut/contacts?q=${encodeURIComponent(query)}&type=${encodeURIComponent(parasutType)}`, {
                        headers: { Accept: 'application/json' },
                    });
                    const payload = await response.json();

                    if (!response.ok || !payload.ok) {
                        renderMessage(payload.message || 'Paraşüt araması yapılamadı.');
                        return;
                    }

                    renderContacts(payload.data || []);
                } catch (error) {
                    renderMessage('Paraşüt API bağlantısı kurulamadı.');
                }
            }, 650);
        });
    };

    document.querySelectorAll('[data-parasut-search]').forEach(initParasutSearch);
})();

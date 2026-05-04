INSERT INTO users (name, email, password_hash, role)
VALUES ('Sistem Yoneticisi', 'admin@example.com', 'sha256$240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', 'admin');

INSERT INTO customers (company_name, contact_name, email, phone, notes)
VALUES
    ('Ornek Musteri A.S.', 'Ayse Demir', 'ayse@example.com', '0212 000 00 00', 'Demo musteri kaydi.'),
    ('Beta Teknoloji', 'Mehmet Kaya', 'mehmet@example.com', '0216 000 00 00', 'Yillik destek sozlesmesi var.');

INSERT INTO customer_contacts (customer_id, full_name, email, phone, notify_enabled)
VALUES
    (1, 'Ayse Demir', 'ayse@example.com', '0212 000 00 00', 1),
    (2, 'Mehmet Kaya', 'mehmet@example.com', '0216 000 00 00', 1);

INSERT INTO supplier_groups (name, is_active)
VALUES
    ('Yazilim', 1),
    ('Donanim', 1),
    ('Bulut', 1),
    ('Guvenlik', 1),
    ('Hosting', 1),
    ('Destek Hizmeti', 1),
    ('Telekom', 1),
    ('Sarf Malzeme', 1);

INSERT INTO suppliers (supplier_group_id, company_name, contact_name, email, phone, notes)
VALUES
    (1, 'Acme Software', 'Selin Yildiz', 'selin@acme.example', '0212 111 11 11', 'Demo yazilim tedarikcisi.'),
    (6, 'Ic Hizmet', 'Operasyon Ekibi', 'operasyon@example.com', '0212 222 22 22', 'Ic servis ve destek tedarikcisi.');

INSERT INTO supplier_contacts (supplier_id, full_name, email, phone, notify_enabled)
VALUES
    (1, 'Selin Yildiz', 'selin@acme.example', '0212 111 11 11', 1),
    (2, 'Operasyon Ekibi', 'operasyon@example.com', '0212 222 22 22', 1);

INSERT INTO renewal_definitions (name, kind, is_active)
VALUES
    ('Microsoft 365 Lisansi', 'product', 1),
    ('Google Workspace Lisansi', 'product', 1),
    ('E-posta Hosting', 'product', 1),
    ('Alan Adi Yenileme', 'product', 1),
    ('Web Hosting', 'product', 1),
    ('SSL Sertifikasi', 'product', 1),
    ('Antivirus / EDR Lisansi', 'product', 1),
    ('Firewall UTM Lisansi', 'product', 1),
    ('VPN Lisansi', 'product', 1),
    ('Yedekleme Yazilimi Lisansi', 'product', 1),
    ('Sunucu Lisansi', 'product', 1),
    ('Windows Server CAL', 'product', 1),
    ('SQL Server Lisansi', 'product', 1),
    ('ERP Lisansi', 'product', 1),
    ('ERP Lisans Yenileme', 'product', 1),
    ('CRM Lisansi', 'product', 1),
    ('Bulut Sunucu', 'product', 1),
    ('IP Santral Lisansi', 'product', 1),
    ('Kamera Kayit Yazilimi Lisansi', 'product', 1),
    ('Bakim ve Destek Sozlesmesi', 'service', 1),
    ('Helpdesk Destek Hizmeti', 'service', 1),
    ('Sunucu Bakim Hizmeti', 'service', 1),
    ('Network Bakim Hizmeti', 'service', 1),
    ('Web Site Bakim Hizmeti', 'service', 1),
    ('Yedekleme Hizmeti', 'service', 1),
    ('Felaket Kurtarma Hizmeti', 'service', 1),
    ('Siber Guvenlik Izleme Hizmeti', 'service', 1),
    ('E-posta Guvenligi Hizmeti', 'service', 1),
    ('Penetrasyon Testi', 'service', 1),
    ('KVKK Danismanlik Hizmeti', 'service', 1);

UPDATE renewal_definitions
SET notification_info = 'Alan adının süresi bittikten sonraki 20 gün içinde alan adı normal ücretle yenilenebilir. Bu süre içinde, alan adına bağlı web sitesi, e-mailler ve benzer bütün servisler duracaktır. 20 günü aştığı taktirde ise alan adı kurtarma periyoduna girer ve normal ücretle yenilenemez, böyle bir durumda yenilemek isterseniz destek bildirimi açarak güncel kurtarma ücretini sorabilirsiniz. Süre bitiminden 20 gün geçtikten sonra alan adınızı kurtarabileceğiniz ve sahipliğini sağlayabileceğiniz konusunda garanti verememekteyiz. Firmalar arası farklılıklar göstermektedir.'
WHERE name = 'Alan Adi Yenileme' AND kind = 'product';

INSERT INTO renewal_periods (name, interval_count, interval_unit, is_active)
VALUES
    ('18 Gun', 18, 'day', 1),
    ('75 Gun', 75, 'day', 1),
    ('1 Ay', 1, 'month', 1),
    ('3 Ay', 3, 'month', 1),
    ('6 Ay', 6, 'month', 1),
    ('1 Yil', 1, 'year', 1);

INSERT INTO renewals
    (customer_id, supplier_id, supplier_group_id, definition_id, renewal_period_id, title, kind, supplier, license_key, start_date, renewal_date, reminder_days, amount, currency, status, notes)
VALUES
    (1, 1, 1, 15, 6, 'ERP Lisans Yenileme', 'product', 'Acme Software', 'ERP-2026-DEMO', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 30, 45000.00, 'TRY', 'active', 'Lisans adetleri yenileme oncesi kontrol edilecek.'),
    (2, 2, 6, 20, 6, 'Bakim ve Destek Sozlesmesi', 'service', 'Ic Hizmet', NULL, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 30, 125000.00, 'TRY', 'active', 'Teklif yenileme sureci 45 gun once baslatilacak.');

INSERT INTO renewal_invoice_periods (renewal_id, period_start_date, period_end_date, invoice_number)
VALUES
    (1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'DEMO-ERP-2026'),
    (2, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'DEMO-BAKIM-2026');

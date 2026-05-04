# Yenilenen Urun ve Hizmet Yenileme ve Takip Sistemi

PHP + MariaDB + Mail altyapisi ile hazirlanmis yenileme takip MVP'si.

## Ozellikler

- Oturum acma ve CSRF korumali formlar
- Musteri kaydi
- Musteri carisine birden fazla yetkili ve yetkili bazli bilgilendirme onayi
- Musteri duzenleme, onayli silme ve silinenler havuzundan geri alma
- Tedarikci kaydi, Parasut tedarikci onerisi, manuel giris ve N+ yetkili
- Marka, tedarikci, lisans/referans ve tarih bilgili urun/hizmet yenileme kayitlari
- Yenileme kaydi bazinda + ile coklu bilgilendirme gunu
- Son 7 gunde gunluk bildirim ve bugun icin `Okudum` ile bildirim durdurma
- Yaklasan, geciken, aktif, yenilendi ve iptal durumlari
- Dashboard istatistikleri
- Ayarlar ekranindan normal SMTP ve Microsoft 365 Exchange mail ayarlari
- Ayarlar > Tanimlamalar ekranindan urun/hizmet adi ve yenileme periyodu yonetimi
- CLI uzerinden e-posta hatirlatma komutu
- Gelistirme ortaminda mail loglama

## Kurulum

1. Veritabani olusturun:

```sql
CREATE DATABASE renewal_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Ayar dosyasini hazirlayin:

```bash
cp config/config.example.php config/config.php
```

`config/config.php` icinde MariaDB kullanici adi ve sifre bilgilerini duzenleyin. Mail ayarlari uygulamadaki `Ayarlar` ekranindan yonetilebilir.

3. Tablolari ve demo veriyi otomatik yukleyin:

```bash
php bin/setup-database.php
```

Mevcut kuruluma yeni alanlari eklemek icin:

```bash
php bin/migrate.php
```

Test ortamini ayri veritabaniyla calistirmak icin:

```bash
APP_CONFIG=config.test.php php bin/setup-database.php
APP_CONFIG=config.test.php php bin/migrate.php
APP_CONFIG=config.test.php php -S 127.0.0.1:8001 -t public public/router.php
```

Alternatif olarak SQL dosyalarini elle yukleyebilirsiniz:

```bash
mysql -u root -p renewal_tracker < database/schema.sql
mysql -u root -p renewal_tracker < database/seed.sql
```

4. Gelistirme sunucusunu baslatin:

```bash
php -S 127.0.0.1:8000 -t public public/router.php
```

Bu makinede PHP PATH icinde degilse Herd PHP ile:

```bash
"$HOME/Library/Application Support/Herd/bin/php" -S 127.0.0.1:8000 -t public public/router.php
```

5. Giris yapin:

- E-posta: `admin@example.com`
- Sifre: `admin123`

## Mail hatirlatmalari

Gelistirme varsayilani `mail.transport = log` oldugu icin e-postalar `storage/logs/mail.log` dosyasina yazilir. Uygulamadaki `Ayarlar` ekranindan aktif mail tipi `Normal SMTP`, `Microsoft 365 Exchange`, `PHP mail()` veya `Log / test modu` olarak secilebilir.

```bash
php bin/reminders.php
```

Microsoft 365 Exchange icin `Ayarlar` ekraninda Tenant ID, Client ID, Client Secret ve Callback URL bilgilerini kaydedin, ardindan `Microsoft ile authenticate et` baglantisini kullanin. Azure/Entra uygulamasinda `Mail.Send` izni ve ayni Callback URL tanimli olmalidir.

Normal SMTP icin SMTP host, port, guvenlik tipi, kullanici adi ve sifre alanlarini doldurun. Kayitli sifre/secret alanlari bos birakildiginda mevcut deger korunur.

Hatirlatmalar sadece musteri carisindeki `Bilgilendirme gonder` kutusu isaretli ve e-posta adresi dolu yetkililere gider. Yenileme kaydinda birden fazla bilgilendirme gunu tanimlanabilir; kalan gun sayisi tanimli esiklere girdiginde bildirim aday olur. Son 7 gunde gunluk bildirim aktiftir. Panelde `Okudum` isaretlenirse ilgili kayit icin o gun yeniden bildirim gonderilmez.

Bildirim saati varsayilan olarak `09:00` gelir ve `Ayarlar > PWA ve Bildirim` bolumunden degistirilebilir. Cron sik calissa bile komut sadece ayarlanan saat penceresinde gonderim yapar.

Cron ornegi:

```cron
*/15 * * * * /usr/bin/php /path/to/project/bin/reminders.php
*/30 * * * * /usr/bin/php /path/to/project/bin/parasut-cache.php
```

## Parasut entegrasyonu

Paraşüt API ayarlari `config/config.php` icindeki `parasut` bolumundedir. Uygulamada `Parasut` sayfasina gidip izin ekranini acin, Paraşüt'ün verdigi onay kodunu ve firma ID bilgisini kaydedin. Bundan sonra `Musteriler` ve `Tedarikciler` ekranindaki `Parasut cari ara` kutusu Paraşüt carilerinden bilgi onerir.

Tum Paraşüt carilerini cache'e almak icin:

```bash
php bin/parasut-cache.php
```

Bu komut cron ile calistirildiginda cari aramalari Paraşüt export listesinden hizli ve tam sonuc verir.

Resmi dokuman: https://apidocs.parasut.com

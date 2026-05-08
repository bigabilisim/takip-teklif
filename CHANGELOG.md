# Changelog

## [2.0.13] - 2026-05-08

- Yeni teklif ekranında cari seçilince öneri kutusunun kapanması ve seçilen carinin e-posta/telefon bilgilerinin otomatik forma yazılması sağlamlaştırıldı.

## [2.0.12] - 2026-05-08

- Paraşüt faturası oluşturulurken fatura notuna başarılı ödeme yöntemi, ödeme ID, sistem ödeme kayıt no, ödeme numarası, tarih ve tutar bilgileri otomatik eklenir.
- Onaylı müşteri tekliflerinden oluşturulan Paraşüt faturaları artık en son başarılı ödeme kaydıyla ilişkilendirilmiş açıklama taşır.
- Kart ödemesi fatura oluştuktan sonra tamamlanırsa mevcut Paraşüt faturasının notu ödeme bilgileriyle otomatik güncellenir.

## [2.0.11] - 2026-05-08

- Yeni teklif oluşturma ekranındaki firma alanı mevcut carileri canlı arama ile listeleyecek hale getirildi.
- Cari seçildiğinde teklif formundaki e-posta ve telefon alanları kayıtlı müşteri/yetkili bilgileriyle otomatik doldurulur.

## [2.0.10] - 2026-05-08

- Raporlar menüsüne ürün/hizmet bazında yıllık-aylık satış analizi eklendi.
- Onaylanan müşteri tekliflerinden ürün adı filtresiyle satış sayısı, satılan adet ve KDV dahil tutarlar izlenebilir hale getirildi.

## [2.0.9] - 2026-05-08

- Paraşüt ürün/hizmet kataloğu yerel stok kalemi tablosuna senkronlanabilir hale getirildi.
- Yeni teklif ve teklif şablonu kalemleri yerel stok kataloğundan seçilerek fiyat, para birimi ve KDV bilgisini otomatik doldurur.

## [2.0.8] - 2026-05-08

- Dashboard sağ tarafı yeni teklif modülü olarak düzenlendi; yeni teklif butonu ve son teklif taslakları eklendi.
- Ayarlar bölümüne teklif şablonları eklendi; boş teklif veya hazır şablondan teklif taslağı oluşturma akışı hazırlandı.

## [2.0.7] - 2026-05-08

- Ürün/hizmet tanımlarının tamamı için kategori bazlı bilgilendirme açıklamaları eklendi.
- Boş açıklamaya sahip mevcut tanımlar, panel açılışında doğal ama riskleri anlatan metinlerle otomatik tamamlanacak hale getirildi.

## [2.0.6] - 2026-05-08

- Onaylı müşteri teklifleri için Paraşüt faturası otomatik oluşmazsa dashboard aksiyonlarına manuel gönderim tuşu eklendi.
- Müşteri teklif geçmişindeki Paraşüt fatura aksiyonu “Manuel Paraşüt'e gönder” olarak netleştirildi.

## [2.0.5] - 2026-05-08

- Tüm yenileme kartları için bilgilendirme günleri 30, 20, 15 ve 7 gün olarak standart hale getirildi.
- Bilgilendirme gönderim mantığı 30/20/15 günlerde tekil, 7 gün ve altında günlük tekrar olacak şekilde düzenlendi.

## [2.0.4] - 2026-05-08

- Yenileme bilgilendirme maillerinden kredi kartı ödeme linki ve butonu kaldırıldı.
- Mail şablon tasarımındaki kredi kartı ödeme alanları bilgilendirme şablonu kullanılabilir alanlarından çıkarıldı.

## [2.0.3] - 2026-05-08

- Alan adı ve hosting yenilemeleri için daha doğal, satış baskısı oluşturmayan ancak servis kesintisi riskini anlatan bilgilendirme metni eklendi.
- Alan adı, hosting ve e-posta hosting tanımlarındaki varsayılan bilgilendirme metni aynı içerikle güncellenecek şekilde hazırlandı.

## [2.0.2] - 2026-05-08

- Müşteri kartlarına eksik yetkili/cari bilgilerini 48 saat geçerli tek kullanımlık linkle mail veya WhatsApp üzerinden isteme akışı eklendi.
- Cari bilgi formu mevcut müşteri ve yetkili bilgileriyle açılarak müşterinin eksik bilgilendirme kişilerini tamamlaması kolaylaştırıldı.

## [2.0.1] - 2026-05-08

- Müşteri teklif onayından sonra Paraşüt satış faturası otomatik oluşturma akışı eklendi.
- Onaylı müşteri teklif geçmişinde Paraşüt fatura no, aktarım durumu, hata detayı ve manuel tekrar oluşturma aksiyonu gösterilir hale geldi.

## [2.0.0] - 2026-05-08

- Hızlı Takip ve Teklif Platformu ana sürümü V2 olarak başlatıldı.
- PWA cache sürümü V2’ye yükseltilerek canlı kullanıcıların yeni arayüz ve ödeme akışı dosyalarını alması sağlandı.

## [1.0.72] - 2026-05-07

- Sabit “30 gün cari hesap” ödeme yöntemi ödeme tanımlarından, yenileme formundan, tahsilat filtrelerinden ve akış şemasından kaldırıldı.
- Müşteri ödeme şeklini kendi seçsin açık olan ödeme bağlantılarında “Diğer” seçeneği eklendi; müşteri kendi ödeme şartını yazıp kaydedebilir hale geldi.

## [1.0.71] - 2026-05-07

- Manuel fiyat seçimi tedarikçi teklifleri olan kartlarda da “Manuel fiyat” etiketiyle gösterilecek şekilde düzeltildi.

## [1.0.70] - 2026-05-07

- Takipteki ürün ve hizmet kartlarına “Manuel fiyat ver” aksiyonu eklendi.
- Tedarikçi teklifi gelmeden ürün kalemlerine manuel satış fiyatı girilip müşteri teklif ekranına aktarılabilir hale getirildi.
- Manuel fiyatla hazırlanan kayıtlar mevcut müşteri teklif gönderme akışına bağlandı.

## [1.0.69] - 2026-05-07

- Başarılı iyzico ödemelerinde Bilal Bozduman kullanıcısına web push ve mail bildirimi gönderimi eklendi.
- Manuel Ödeme Talep Et kaydı oluştuğunda Bilal Bozduman kullanıcısına web push bildirimi gönderimi eklendi.
- Ödeme bildirimleri tekrar eden iyzico callbacklerinde yalnızca ilk başarılı geçişte gönderilecek şekilde sınırlandı.

## [1.0.68] - 2026-05-07

- iyzico kredi kartı linklerinde USD/EUR seçildiğinde tutar TCMB satış kuruyla TL’ye çevrilip TRY POS üzerinden ödeme linki oluşturulacak şekilde düzeltildi.
- Kredi kartı tahsilatı formuna iyzico’nun TL POS davranışını ve yaklaşık TL karşılığını açıklayan bilgilendirme eklendi.
- Kart ödeme kayıtlarında iyzico’ya gönderilen TL tutar saklanırken ham istek kaydına orijinal döviz tutarı ve kur bilgisi eklendi.

## [1.0.67] - 2026-05-07

- WhatsApp gönderim bağlantıları `wa.me` yerine doğrudan WhatsApp Web gönderim ekranına yönlendirilecek şekilde değiştirildi.
- WhatsApp butonları aynı isimli `takip_whatsapp_web` sekmesini/penceresini kullanır hale getirildi; sekme yoksa açar, varsa aynı sekmede devam eder.

## [1.0.66] - 2026-05-07

- Mail log detayındaki içerik alanı ham HTML yerine render edilmiş mail önizlemesi olarak gösterilecek şekilde değiştirildi.
- Önizlemede kayıtlı uygulama logosu `cid:app_logo` yerine paneldeki logo adresiyle gösterilir hale getirildi.

## [1.0.65] - 2026-05-07

- Loglar ekranı “Mail logları” olarak yeniden adlandırıldı ve Ayarlar bölümünün altına taşındı.
- Eski `/logs` adresi yeni `/settings/mail-logs` adresine yönlendirilecek şekilde korundu.
- Mail gönderim kayıtlarına Detay açılımı eklendi; alıcı, konu, durum, tarih ve gönderilen mail içeriği güvenli içerik görünümüyle incelenebilir hale getirildi.

## [1.0.64] - 2026-05-07

- Manuel ödeme talebi formuna kayıtlı cariden canlı unvan arama ve seçme eklendi.
- Cari seçilince e-posta, telefon, vergi no alanları otomatik doldurulur hale getirildi.
- Seçilen carinin yetkilileri kutucuklar halinde gösterilip ödeme linki için istenen yetkilileri seçme ve oluşturulan talepten yetkili bazlı WhatsApp/mail gönderme eklendi.

## [1.0.63] - 2026-05-07

- Ödeme Talep Et sayfası yenileme/tahsilat kaydından bağımsız manuel ödeme case akışına çevrildi.
- Manuel ödeme talepleri için tutar, para birimi, müşteri iletişimi, açıklama, ödeme linki, WhatsApp ve mail gönderim aksiyonları eklendi.
- Manuel ödeme taleplerine özel ödeme ve gönderim log tabloları eklendi; iyzico dönüşleri manuel talepleri ödendi durumuna alacak şekilde genişletildi.

## [1.0.62] - 2026-05-07

- Sol menüye “Ödeme Talep Et” sayfası eklendi.
- Açık tahsilat kayıtları için ödeme talebi oluşturma, WhatsApp ile gönderme, mail atma ve direkt ödeme linki açma aksiyonları eklendi.
- Ödeme talebi mail gönderimleri mail loglarına işlenir hale getirildi.

## [1.0.61] - 2026-05-07

- Akış Şemaları sol menüden kaldırılıp Ayarlar bölümünün içine taşındı.
- Yeni akış adresi `/settings/flows` oldu; eski `/flows` bağlantısı yeni adrese yönlendirilir.
- Ayarlar üst aksiyonlarına Akış Şemaları butonu eklendi.

## [1.0.60] - 2026-05-07

- Ayarlar ekranındaki kategori kartları satır hizasına sabitlendi; kartlar artık kolonlarda boşluk bırakarak dağılmıyor.
- Kategori düzeninde sürükleme kontrolü gizlendi; ayar kartları daha temiz tek aksiyonlu satır yapısına alındı.

## [1.0.59] - 2026-05-07

- Ayarlar ekranı üç kategoriye ayrıldı: İletişim ve marka, Entegrasyon ve ödeme, Sistem.
- Ayar kartları daha kompakt tek satır yapıya alındı; durum rozeti ve Ayarla aksiyonu aynı satırda kalacak şekilde düzenlendi.
- Sıralama kontrolü görsel olarak küçültüldü ve mobilde kategori kolonları tek kolona düşecek şekilde responsive hale getirildi.

## [1.0.58] - 2026-05-07

- Kredi kartı ödemesinde yalnızca doğrulanmış iyzico ödeme numarası olan başarılı kayıtlar tahsil edilmiş sayılacak şekilde kontrol sıkılaştırıldı.
- Eski bir başarılı kart ödemesinin yeni ödeme linkini “tamamlandı” göstermemesi için ödeme seçimi tarihinden sonraki tahsilat kontrolü eklendi.
- iyzico dönüşünde tutar ve ödeme numarası doğrulaması güçlendirildi; ödeme tamamlanmadıysa müşteri tekrar ödeme adımına yönlenir.

## [1.0.57] - 2026-05-07

- PayTR kredi kartı entegrasyonu ve PayTR ayar kartı sistemden kaldırıldı.
- Kredi kartı ödeme akışı tekrar yalnızca iyzico Checkout Form üzerinden çalışacak şekilde sadeleştirildi.
- Yenileme düzenleme ekranındaki kredi kartı tahsilatı bilgilendirmeleri iyzico odaklı hale getirildi.

## [1.0.56] - 2026-05-07

- Sol menüye “Akış Şemaları” bölümü eklendi.
- Müşteri ürün takip, teklif ve tahsilat süreçleri için mevcut kayıtlardan beslenen flowchart ekranı eklendi.
- Flowchart ekranında bekleyen, tamamlanan ve acil işlem gerektiren adımlar renkli durum kartlarıyla görünür hale getirildi.

## [1.0.55] - 2026-05-07

- Ayarlar ekranındaki ayar düzenleme akışı popup pencereye taşındı.
- Ayar kutuları pasif seçim kartları olarak kalır; Ayarla butonu ilgili ayarı modal pencerede açar.
- Popup kapatılınca veya kaydetme sonrası ayar kartları tekrar pasif kutu düzenine döner.

## [1.0.54] - 2026-05-07

- Eski tarihli tamamlanmış kayıtların Tahsilat ekranına düşmesi engellendi.
- Eski Yenileme giriş açıklaması, tahsilatı alınmış geçmiş kayıt mantığını daha net anlatacak şekilde güncellendi.

## [1.0.53] - 2026-05-07

- Dashboard üzerindeki yeni kayıt aksiyonu “Yeni Takip” menüsüne dönüştürüldü.
- Yeni Takip menüsünden “Yeni Yenileme” ve “Eski Yenileme” seçenekleri açılır hale getirildi.

## [1.0.52] - 2026-05-07

- Ayarlar ekranında seçilen ayarın düzenleneceği ayrı bir sayfa üstü bölüm eklendi.
- Ayar kutuları küçük seçim kartları olarak kaldı; Ayarla butonuyla ilgili form düzenleme alanına taşınır.
- Sürükle-bırak sıralama kutu alanında korunacak şekilde düzenlendi.

## [1.0.51] - 2026-05-07

- Ayarlar ekranı modüler kart yapısına alındı.
- Her ayar kartı aç/kapat düzeninde çalışır hale getirildi.
- Ayar kartları sürükle-bırak ile sıralanabilir hale getirildi; sıralama tarayıcıda korunur.

## [1.0.50] - 2026-05-06

- Sol menüye Tahsilat ekranı eklendi.
- Ödeme bekleyen yenilemeler; ödenmemiş, Havale / EFT, 30 gün cari ve seçim bekleyen filtreleriyle izlenebilir hale getirildi.
- Tahsilat ekranından müşteri yetkililerine manuel tahsilat hatırlatma maili gönderimi eklendi.
- Tahsilat mailinde ödeme / tercih linki, PDF özet linki, toplam tutar ve ödeme şartı bilgileri gösterilir.

## [1.0.49] - 2026-05-06

- Müşteri teklif linki görüntüleme sayacı eklendi.
- Müşteri teklif ekranında “Revize iste” butonu ilk üç görüntülemede gizlendi; dördüncü görüntülemeden itibaren aktif olur.
- Revize talebi buton gizliyken manuel gönderilmeye çalışılsa da sistem tarafından engellenir.

## [1.0.48] - 2026-05-06

- Müşteri teklif onayından sonra ödeme tamamlanmadan link tekrar açılırsa ödeme adımına devam edilebilir hale getirildi.
- Kredi kartı seçimi, ödeme sağlayıcısından başarılı ödeme kaydı gelmeden “tamamlandı” kabul edilmez.
- Ödeme tercih ekranında yarım kalan kredi kartı ödemeleri için müşteriye devam uyarısı gösterilir.

## [1.0.47] - 2026-05-06

- Müşteri yenileme teklifi başlığı Türkçe karakterlerle sabit yazılacak şekilde düzenlendi.
- Müşteri teklif sayfasındaki başlık alanı daha belirgin, yumuşak çerçeveli bir kart olarak yeniden tasarlandı.
- PDF/yazdırma görünümünde başlık sade ve Türkçe karakterleri bozmadan görünür hale getirildi.

## [1.0.46] - 2026-05-06

- Yenileme kaydına “Tedarikçiye cari bilgisini gönder” seçeneği eklendi.
- Seçenek kapalıysa tedarikçi fiyat talebi maili, teklif formu ve onay/okundu akışlarında müşteri adı yerine “Cari bilgisi gizli” gösterilir.
- Tedarikçi fiyat talebi mesajı, cari gizliliği tercihine göre otomatik hazırlanır.

## [1.0.45] - 2026-05-06

- Müşteri teklif geçmişi kayıtları yenileme kartı üzerinden silinebilir hale getirildi.
- Silinen müşteri teklifinin satırları da temizlenir ve müşteriye bilgi maili gönderilmez.

## [1.0.44] - 2026-05-06

- Tedarikçiye giden teklif seçim mailindeki “Teklifiniz seçildi” ifadesi “Onaylandı” olarak değiştirildi.
- Tedarikçi teklif onayı akışındaki panel, buton ve okundu bildirim metinleri onay diliyle tutarlı hale getirildi.

## [1.0.43] - 2026-05-06

- Müşteri teklif/PDF sayfasına şirket anteti eklendi.
- Teklif çıktısında logo, firma adı, e-posta ve web adresi görünür hale getirildi.
- Müşteri teklif sayfasının genişliği antet ve kalemler için daha rahat okunacak şekilde sabitlendi.

## [1.0.42] - 2026-05-06

- Müşteri teklif sayfasındaki ana başlık daha kompakt ve tek satır kalacak şekilde düzenlendi.
- Teklif kalemlerinde ürün adı ve fiyat kolonları yeniden hizalanarak ürün adının dikey kırılması giderildi.
- Müşteri teklif sayfasına PDF olarak yazdırma butonu ve yazdırma görünümü eklendi.

## [1.0.41] - 2026-05-06

- Tedarikçi teklif karşılaştırma kartında kalem başlığının dikey kırılmasına neden olan kolon sıkışması düzeltildi.
- Seçili tedarikçi teklifi özeti daha okunur, ayrı bir bilgi kutusu gibi düzenlendi.

## [1.0.40] - 2026-05-06

- Yenileme kartlarındaki müşteri maili ve WhatsApp gönderimleri tek “Müşteriye gönder” butonunda birleştirildi.
- Müşteriye gönder penceresi, tedarikçi fiyat talebi akışına benzer iki sütunlu mail ve WhatsApp düzenine alındı.
- Dashboard ve yenileme listesinde ayrı mail/WhatsApp butonları sadeleştirildi.

## [1.0.39] - 2026-05-06

- HTML ve düz metin mail gönderimleri, logo ayarı varsa standart inline logo ile markalı hale getirildi.
- Mail gövdelerinde logo dış bağlantı yerine CID gömülü görsel olarak kullanılacak şekilde merkezileştirildi.
- Yeni eklenen mail şablonlarında logo unutulmaması için kontrol `Mailer` katmanına taşındı.

## [1.0.38] - 2026-05-06

- Tedarikçi fiyat talebi maillerine tedarik listesinden çıkış bağlantısı eklendi.
- Tedarikçiler için kategori bazlı veya tüm kategorilerden çıkış tercihi tutulur hale getirildi.
- Tedarikçi fiyat talebi alıcıları çıkış tercihlerine göre filtrelenir hale getirildi.

## [1.0.37] - 2026-05-06

- Tedarikçi fiyat talebi mail başlığı Türkçe büyük harflerle sabitlendi.
- Panel, public formlar ve ayar ekranlarındaki Türkçe karakter eksikleri düzeltildi.

## [1.0.36] - 2026-05-06

- Müşteriler ekranında tüm cariler ve son eklenen cariler alanları yarı yarıya iki sütun olacak şekilde ayarlandı.

## [1.0.35] - 2026-05-06

- Müşteriler ekranı iki sütuna ayrıldı: solda canlı filtrelenen tüm cariler, sağda son eklenen 10 cari kartı gösterilir hale getirildi.
- Müşteri listesine alfabetik canlı filtre alanı eklendi.

## [1.0.34] - 2026-05-06

- Tedarikçi teklif formunda KDV seçimi checkbox yerine KDV Dahil / KDV Hariç hızlı seçim alanına dönüştürüldü.

## [1.0.33] - 2026-05-06

- Tedarikçi teklif formunda ödeme seçeneği fiyat kartları masaüstünde satır başına iki kart olacak şekilde sıkılaştırıldı.

## [1.0.32] - 2026-05-06

- Tedarikçi teklif formu başlığı tek satıra daha rahat sığacak şekilde küçültüldü.

## [1.0.31] - 2026-05-06

- Tedarikçi teklif formunda nakliye/teslim şartı ve kalem notu alanları KDV dahil seçeneğinin altına taşındı.

## [1.0.30] - 2026-05-06

- Tedarikçi teklif formundaki fiyat girişi açıklaması para birimi seçiminin hemen altına taşındı.

## [1.0.29] - 2026-05-06

- Tedarikçi teklif formu daha kompakt ve yönlendirici bir fiyat giriş akışına dönüştürüldü.
- Para birimi, birim fiyat, vade seçenekleri, toplam önizleme, KDV ve not alanları daha okunur kart düzeniyle ayrıldı.

## [1.0.28] - 2026-05-06

- Tedarikçi teklif formunda KDV dahil seçeneği varsayılan kapalı hale getirildi.
- Tedarikçi fiyat alanları seçili para birimini, birim fiyatı ve adetle hesaplanan toplam tutarı aynı kartta gösterecek şekilde düzenlendi.

## [1.0.27] - 2026-05-05

- Tedarikçiye giden "teklifiniz seçildi" mailine kişiye özel Okudum bağlantısı eklendi.
- Tedarikçi seçim maili okunma durumu, teklif kartlarında okundu / okunmadı olarak görünür hale getirildi.
- Tedarikçi Okudum bağlantısına ilk kez tıkladığında yöneticilere web push bildirimi gönderilmesi sağlandı.

## [1.0.26] - 2026-05-05

- PayTR iFrame API ile kredi kartı ödeme entegrasyonu eklendi.
- Ayarlar bölümüne PayTR mağaza no, merchant key, merchant salt, test/canlı mod, taksit ve bildirim URL ayarları eklendi.
- Kredi kartı ödeme akışı PayTR aktif ve eksiksizse PayTR formuna, aksi durumda mevcut iyzico akışına yönlenecek şekilde düzenlendi.
- PayTR ödeme callback doğrulaması, ödeme sonucu kaydı ve müşteri ödeme sonucu ekranı eklendi.

## [1.0.25] - 2026-05-05

- Tedarikçi teklif paneline tüm tedarikçi taleplerini gösteren kompakt talep listesi eklendi.
- Seçilen tedarikçi teklif talebi ve bağlı fiyat satırları sessizce silinebilir hale getirildi; tedarikçiye bilgi maili gönderilmez.
- Bir yenileme için 3 tedarikçi teklif verdikten sonra açık kalan diğer tedarikçi formları otomatik kapatılır hale getirildi.
- Otomatik kapanan tedarikçilere "3 teklif alındı, süreç kapatıldı" bilgilendirme maili gönderilmesi sağlandı.

## [1.0.24] - 2026-05-05

- Secilen tedarikci tekliflerinden musteriyi onay/revize/red baglantisina yonlendiren musteri teklif akisi eklendi.
- Para birimi secimleri TRY, USD ve EUR ile sinirlandirildi.
- Müşteri tekliflerinde ve tedarikçi fiyat kartlarında birim fiyat, toplam ve KDV dahil fiyat birlikte gosterilir hale getirildi.
- Müşteri teklif gecmisi, durumlari ve satir detaylari takip karti icinde acilir sekilde gorunur hale getirildi.
- Müşteri teklifi onaylandiginda yenileme tutari satir fiyatlarina gore guncellenir ve secilen tedarikcilere isleme alma maili gonderilir hale getirildi.

## [1.0.23] - 2026-05-05

- Tedarikci teklifleri arasindan bir kalem/vade secildiginde tedarikciye secim maili gonderilmesi saglandi.
- Secim mailinde musteri, urun/hizmet, secilen vade, fiyat, KDV ve varsa nakliye/not bilgileri yer alir hale getirildi.
- Zaten secili olan teklif butonu tekrar mail gitmesini engellemek icin pasif hale getirildi.

## [1.0.22] - 2026-05-05

- Manuel tedarikci teklif linki olustururken linkin e-posta olarak da gonderilmesi saglandi.
- Olusturulan teklif linki kartlarinda mail gonderildi / gonderilemedi / e-posta yok durumlari gosterilmeye baslandi.
- Tedarikciden fiyat al penceresindeki manuel link bolumu, mail gonderim davranisini daha net anlatacak sekilde duzenlendi.

## [1.0.21] - 2026-05-05

- Takipteki urun ve hizmet kartlarinda 14 gun ve altinda kalan gun rengi sari-kirmizi skalasina baglandi.
- Kalan gun alani ve durum rozeti aciliyet yaklastikca pulse/yanip-sonme efektiyle vurgulanir hale getirildi.
- Suresi gecen kayitlarda aciliyet rengi kan kirmizisi olarak sabitlendi.

## [1.0.20] - 2026-05-05

- Tedarikci teklif linki olusturma islemi sayfa yonlendirmesi yapmadan ayni pencerede calisir hale getirildi.
- Teklif linki olusturuldugunda gercek tedarikci form linki panel icinde aninda gosteriliyor.
- `supplier_price_dialog` parametresiyle dialogu tekrar acma akisi kaldirilarak kilitlenme hissi giderildi.

## [1.0.19] - 2026-05-04

- Teklif linki olusturma sonrasi pencere tekrar acildiginda ilk form linkinin yanlislikla tetiklenmesi engellendi.
- PWA cache surumu yenilendi.

## [1.0.18] - 2026-05-04

- Tedarikciden fiyat al penceresine mail gondermeden teklif formu linki olusturma alani eklendi.
- Olusturulan tedarikci teklif linkleri pencerede kopyalanabilir ve form olarak acilabilir sekilde gosterildi.
- Link olusturulduktan sonra ilgili pencerenin otomatik tekrar acilmasi saglandi.

## [1.0.17] - 2026-05-04

- Tedarikci teklif toplama akisi eklendi: mail ve WhatsApp talepleri artik tedarikci teklif formu linki uretir.
- Tedarikci public teklif formuna pesin, 30 gun, 60 gun, cek/vade, ozel vade, KDV, nakliye/teslim ve not alanlari eklendi.
- Fiyat yazmak istemeyen tedarikciler icin teklif dosyasi veya genel teklif notu ile gonderim destegi eklendi.
- Dashboard kartlarinda gelen tedarikci tekliflerini kalem bazli karsilastirma ve her kalemde farkli tedarikci/vade secme destegi eklendi.

## [1.0.16] - 2026-05-04

- Takipteki urun ve hizmet kartlarina Tedarikciden fiyat al aksiyonu eklendi.
- Tedarikci fiyat talebi icin mail gonderme ve WhatsApp hazir mesaj acma ekranlari eklendi.
- Tedarikci yetkililerinde mail veya telefon bazli fiyat talebi alicilari desteklendi.

## [1.0.15] - 2026-05-04

- Tanimlamalar ekrani 4 kolonlu kompakt kart yapisina tasindi.
- Yeni tanim, periyot, tedarikci grubu ve odeme tanimi ekleme formlari butonla acilan pencerelere alindi.
- Her tanimlama basligina Detay ac/kapat bolumu eklendi.

## [1.0.14] - 2026-05-04

- Sistem denetiminde Parasut cari arama ve yetkili doldurma baglantisi duzeltildi.
- Route okuma yardimcisi CLI ve eksik sunucu degiskenlerine karsi guclendirildi.

## [1.0.13] - 2026-05-04

- Tedarikciler ekraninda yeni tedarikci ekleme formu butonla acilan pencereye tasindi.

## [1.0.12] - 2026-05-04

- Dashboard icindeki Dolar / Euro doviz karti sayfanin en altina tasindi.

## [1.0.11] - 2026-05-04

- Takipteki urun ve hizmet kartlarina Mail olarak gonder ve WhatsApp PDF gonder aksiyonlari eklendi.
- Yenileme icin public PDF/ozet sayfasi ve yazdir/PDF kaydet gorunumu eklendi.

## [1.0.10] - 2026-05-04

- Giris ekraninda Dolar / Euro kuru login kartinin disina alinarak sayfanin en altina tasindi.

## [1.0.9] - 2026-05-04

- Okuyan yetkililer bilgisi yan yana küçük kutucuklar halinde gosterilmeye baslandi.

## [1.0.8] - 2026-05-04

- Mobil giris ekraninda Dolar / Euro karti kucultulup formun altinda daha kompakt konumlandirildi.

## [1.0.7] - 2026-05-04

- Mobil kartlarda Kalan etiketi ve gun bilgisi ayni satira alindi.

## [1.0.6] - 2026-05-04

- Giris ekrani basligi "Hizli Takip ve Teklif Platformu" olarak guncellendi.
- Giris aciklama metni urun, hizmet yenileme ve teklif sureclerini vurgulayacak sekilde degistirildi.
- Balik simgesi sifremi unuttum aksiyonuna tasindi.

## [1.0.5] - 2026-05-04

- Yenilemeler menusu kaldirildi; dashboard ana takip ekranina donusturuldu.
- Dashboard iki sutunlu hale getirildi ve sag taraf yeni modul icin bos birakildi.
- Yenilenen urunler sol kolonda kompakt acilir kartlarla gosterilmeye baslandi.

## [1.0.4] - 2026-05-04

- Maildeki Okudum tiklamasi gunluk okundu kaydina da islenir hale getirildi.
- Yenileme kartlarina alici bazli okundu sayaci eklendi.
- Mail log ekraninda eski takip kayitlari icin okundu eslestirmesi guclendirildi.

## [1.0.3] - 2026-05-04

- Oturum suresi gunluk yenileme mantigina alindi.
- Kullanici basina ayni anda iki aktif cihaz oturumu desteklendi.

## [1.0.2] - 2026-05-04

- Yenileme mailindeki kredi karti aksiyonu "Kredi kartı ile hemen öde" olarak netlestirildi.
- Okudum alanindaki aciklama bilgi ikonu ile gosterilecek sekilde duzenlendi.

## [1.0.1] - 2026-05-04

- Giris ekraninda form kur bilgisinin ustune alindi.
- Giris ekrani metinleri ve mobil gorunum sikilastirildi.
- Doviz karti daha kompakt hale getirildi.

## [1.0.0] - 2026-05-04

- Uygulama icin ilk resmi surum etiketi eklendi.
- Panel ve giris ekraninda surum bilgisi gosterilmeye baslandi.
- PWA cache adi uygulama surumuyle esitlendi.

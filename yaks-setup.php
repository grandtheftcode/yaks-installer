<?php
// Hata raporlamayı geliştirme sırasında açın, üretimde kapatın
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- Ayarlar ---
// !!! BURAYI DÜZENLEYİN: İndirilecek ZIP dosyasının sabit URL'si !!!
$zipUrl = "https://github.com/grandtheftcode/yaks-nodejs/archive/refs/heads/main.zip"; // <-- İNDİRİLECEK ZIP URL'SİNİ BURAYA YAZIN

$downloadDir = __DIR__."/../yaksnodejs/"; // Betiğin çalıştığı dizin
$maidir = $downloadDir . "/yaks-nodejs-main";
$zipFileName = $downloadDir . '/downloaded_dump.zip';
$extractedSqlPath = $downloadDir . '/yaks_db.sql';
$knexfilePath = $downloadDir . '/knexfile.js'; // Oluşturulacak Knex dosyası
// ----------------

function moveDirectoryContent($source, $destination) {
    if (!is_dir($source)) {
        return false;
    }

    // Hedef dizin yoksa oluştur
    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        // Dosya veya dizin adını al
        $path = $item->getPathname();
        $relativePath = substr($path, strlen($source) + 1);
        $targetPath = $destination . DIRECTORY_SEPARATOR . $relativePath;

        // Dizinse oluştur, dosyaysa taşı
        if ($item->isDir()) {
            mkdir($targetPath);
        } else {
            rename($path, $targetPath);
        }
    }

    // Orijinal dizini sil
    rmdir($source);
    
    return true;
}



// Gerekli eklentileri kontrol et
if (!extension_loaded('zip')) {
    die('HATA: PHP "zip" eklentisi sunucunuzda yüklü veya aktif değil.');
}
if (!extension_loaded('mysqli')) {
    die('HATA: PHP "mysqli" eklentisi sunucunuzda yüklü veya aktif değil.');
}
if (!is_writable($downloadDir)) {
    die("HATA: Dizin yazılabilir değil: " . htmlspecialchars($downloadDir) . ". Lütfen PHP için yazma izinlerini kontrol edin.");
}
if (empty($zipUrl) || !filter_var($zipUrl, FILTER_VALIDATE_URL)) {
    die("HATA: Betikte tanımlanan ZIP URL'si geçerli değil. Lütfen \$zipUrl değişkenini kontrol edin.");
}


$errorMessage = '';
$successMessage = '';
$stepMessages = []; // İşlem adımlarını saklamak için

// Form gönderildi mi?
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Formdan veritabanı verilerini al
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? ''; // Şifreyi trim etmeyin
    $dbName = trim($_POST['db_name'] ?? '');

    // Temel doğrulama
    if (empty($dbHost) || empty($dbUser) || empty($dbName)) {
        $errorMessage = "Lütfen tüm veritabanı bilgilerini eksiksiz girin (Şifre boş olabilir).";
    } else {
        try {
            // --- 1. Adım: ZIP Dosyasını İndir ---
            $stepMessages[] = "1. Adım: ZIP dosyası indiriliyor (URL kod içinde sabit): " . htmlspecialchars($zipUrl);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $zipUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Gerekirse ve güveniyorsanız
            $zipData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($zipData === false || $httpCode >= 400) {
                throw new Exception("ZIP indirme hatası (HTTP Kodu: {$httpCode}): " . htmlspecialchars($curlError));
            }
            if (file_put_contents($zipFileName, $zipData) === false) {
                throw new Exception("İndirilen ZIP dosyası diske yazılamadı: " . htmlspecialchars($zipFileName));
            }
            $stepMessages[] = " -> İndirme başarılı. Dosya kaydedildi: " . htmlspecialchars($zipFileName);
            unset($zipData);

            // --- 2. Adım: ZIP Dosyasını Aç ve SQL Dosyasını Çıkar ---
            $stepMessages[] = "2. Adım: ZIP dosyası açılıyor ve SQL dosyası aranıyor...";
            $zip = new ZipArchive;
            if ($zip->open($zipFileName) !== TRUE) {
                throw new Exception("ZIP dosyası açılamadı.");
            }

            $sqlFileIndex = -1;
            $sqlFileNameInZip = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (substr(strtolower($filename), -4) === '.sql' && substr($filename, -1) !== '/') {
                    $sqlFileIndex = $i;
                    $sqlFileNameInZip = $filename;
                    $stepMessages[] = " -> SQL dosyası bulundu: " . htmlspecialchars($sqlFileNameInZip);
                    break;
                }
            }

            if ($sqlFileIndex === -1) {
                $zip->close();
                throw new Exception("ZIP arşivi içinde '.sql' uzantılı bir dosya bulunamadı.");
            }

            // SQL dosyasını çıkar (orijinal adıyla)
             $extractedActualFileName = $downloadDir . '/' . $sqlFileNameInZip;
           if (!$zip->extractTo($downloadDir)) {
                 $zip->close(); // Hata olsa bile kapatmayı dene
                throw new Exception("ZIP içeriği çıkarılamadı. Hedef klasör izinlerini kontrol edin: " . htmlspecialchars($downloadDir));
            }

            // Standart isme taşıyalım (opsiyonel ama temizlik için iyi)
             if ($extractedActualFileName !== $extractedSqlPath) {
                 if (!rename($extractedActualFileName, $extractedSqlPath)) {
                     // Taşıma başarısız olursa eski isimle devam etmeyi deneyebiliriz ama hata vermek daha iyi.
                     $zip->close();
                     throw new Exception("Çıkarılan SQL dosyası yeniden adlandırılamadı: " . htmlspecialchars($extractedActualFileName) . " -> " . htmlspecialchars($extractedSqlPath));
                 }
                 $stepMessages[] = " -> SQL dosyası başarıyla çıkarıldı ve yeniden adlandırıldı: " . htmlspecialchars($extractedSqlPath);
             } else {
                 $stepMessages[] = " -> SQL dosyası başarıyla çıkarıldı: " . htmlspecialchars($extractedSqlPath);
             }

            $zip->close();

             moveDirectoryContent($maidir, $downloadDir);
            // --- 3. Adım: Veritabanına Bağlan ve SQL Dump'ı Yükle ---
            $stepMessages[] = "3. Adım: MySQL veritabanına bağlanılıyor...";
            $conn = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
            if (!$conn) {
                throw new Exception("MySQL bağlantı hatası: (" . mysqli_connect_errno() . ") " . mysqli_connect_error());
            }
            $stepMessages[] = " -> Veritabanı bağlantısı başarılı: " . htmlspecialchars($dbHost) . "/" . htmlspecialchars($dbName);
            if (!mysqli_set_charset($conn, "utf8mb4")) {
                $stepMessages[] = " -> Uyarı: Karakter seti utf8mb4 olarak ayarlanamadı: " . mysqli_error($conn);
            } else {
                $stepMessages[] = " -> Karakter seti utf8mb4 olarak ayarlandı.";
            }

            $stepMessages[] = " -> SQL dosyası okunuyor: " . htmlspecialchars($extractedSqlPath);
            $sqlContent = file_get_contents($extractedSqlPath);
            if ($sqlContent === false) {
                mysqli_close($conn);
                throw new Exception("SQL dosyası okunamadı: " . htmlspecialchars($extractedSqlPath));
            }
            $stepMessages[] = " -> SQL dosyası okundu. İçerik veritabanına yükleniyor...";

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            if (mysqli_multi_query($conn, $sqlContent)) {
                do {
                    if ($result = mysqli_store_result($conn)) {
                        mysqli_free_result($result);
                    }
                } while (mysqli_next_result($conn));

                if (mysqli_errno($conn)) {
                    throw new Exception("SQL yükleme sırasında hata (multi-query sonrası): (" . mysqli_errno($conn) . ") " . mysqli_error($conn));
                }
                $stepMessages[] = " -> SQL dump başarıyla veritabanına yüklendi!";
                $successMessage = "Veritabanı içe aktarma işlemi başarıyla tamamlandı!";
                mysqli_report(MYSQLI_REPORT_OFF);
                mysqli_close($conn); // Bağlantıyı SQL yüklemesi sonrası kapat
                $stepMessages[] = " -> Veritabanı bağlantısı kapatıldı.";

                // --- 4. Adım: knexfile.js Oluştur ---
                $stepMessages[] = "4. Adım: knexfile.js oluşturuluyor...";

                // JavaScript stringleri için temel kaçış yapalım
                $jsDbHost = addslashes($dbHost);
                $jsDbUser = addslashes($dbUser);
                $jsDbPass = addslashes($dbPass);
                $jsDbName = addslashes($dbName);

                 // HEREDOC kullanarak knexfile içeriğini oluşturalım
                $knexConfigContent = <<<JS
// Bu dosya PHP betiği tarafından otomatik olarak oluşturuldu
module.exports = {
  development: {
    client: 'mysql2', // Node.js projenizde 'mysql2' paketinin kurulu olduğundan emin olun, değilse 'mysql' kullanın
    connection: {
      host: '{$jsDbHost}',
      user: '{$jsDbUser}',
      password: '{$jsDbPass}',
      database: '{$jsDbName}',
      charset: 'utf8mb4' // Karakter setini utf8mb4 olarak ayarlamak iyi bir pratiktir
    },
    migrations: {
      tableName: 'knex_migrations', // Varsayılan migrasyon tablosu adı
      // directory: './migrations' // Migrasyon dosyalarınızın yolu (gerekirse)
    },
    seeds: {
      // directory: './seeds/dev' // Seed dosyalarınızın yolu (gerekirse)
    }
  },

  // İhtiyaç duyarsanız diğer ortamları (production, staging vb.) buraya ekleyebilirsiniz
  // production: {
  //   client: 'mysql2',
  //   connection: {
  //     host: process.env.DB_HOST, // Ortam değişkenlerinden okumak daha güvenlidir
  //     user: process.env.DB_USER,
  //     password: process.env.DB_PASSWORD,
  //     database: process.env.DB_NAME,
  //     charset: 'utf8mb4'
  //   },
  //   migrations: {
  //     tableName: 'knex_migrations'
  //   }
  // }
};
JS;

                if (file_put_contents($knexfilePath, $knexConfigContent) === false) {
                     // Bu bir hata değil, sadece bir uyarı olarak ele alınabilir
                     $stepMessages[] = " -> UYARI: knexfile.js dosyası oluşturulamadı veya yazılamadı: " . htmlspecialchars($knexfilePath);
                     $errorMessage .= " Uyarı: knexfile.js oluşturulamadı."; // Mevcut hataya ekleyebiliriz veya ayrı bir uyarı mesajı
                } else {
                    $stepMessages[] = " -> knexfile.js başarıyla oluşturuldu: " . htmlspecialchars($knexfilePath);
                    $successMessage .= " knexfile.js de başarıyla oluşturuldu!";
                }

            } else {
                 // İlk multi_query hatası
                 throw new Exception("SQL yükleme hatası (multi-query başlangıcı): (" . mysqli_errno($conn) . ") " . mysqli_error($conn));
            }


        } catch (Exception $e) {
            $errorMessage = "İŞLEM BAŞARISIZ OLDU: " . $e->getMessage();
            // Hata durumunda bağlantı açıksa kapat
            if (isset($conn) && $conn && mysqli_ping($conn)) {
                mysqli_report(MYSQLI_REPORT_OFF); // Kapatmadan önce raporlamayı kapat
                mysqli_close($conn);
                $stepMessages[] = " -> Hata nedeniyle veritabanı bağlantısı kapatıldı.";
            }
             // Hata durumunda ZIP açıksa kapat
             if (isset($zip) && $zip instanceof ZipArchive && $zip->filename) {
                 @$zip->close();
             }
        } finally {
            // --- 5. Adım: Temizlik (SQL ve ZIP için) ---
            $stepMessages[] = "5. Adım: Geçici dosyalar siliniyor (ZIP ve SQL)...";
            if (file_exists($zipFileName)) {
                if (unlink($zipFileName)) {
                    $stepMessages[] = " -> İndirilen ZIP dosyası silindi: " . htmlspecialchars($zipFileName);
                } else {
                    $stepMessages[] = " -> UYARI: İndirilen ZIP dosyası silinemedi: " . htmlspecialchars($zipFileName);
                }
            }
            if (file_exists($extractedSqlPath)) {
                if (unlink($extractedSqlPath)) {
                    $stepMessages[] = " -> Çıkarılan SQL dosyası silindi: " . htmlspecialchars($extractedSqlPath);
                } else {
                    $stepMessages[] = " -> UYARI: Çıkarılan SQL dosyası silinemedi: " . htmlspecialchars($extractedSqlPath);
                }
            }
             // knexfile.js'yi SİLMİYORUZ!
        }
    }
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yaks Kurulumu</title>
    <style>
        /* Öncekiyle aynı stil tanımlamaları... */
        body { font-family: sans-serif; line-height: 1.6; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ccc; border-radius: 5px; background-color: #f9f9f9; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="password"] { width: calc(100% - 22px); padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 3px; }
        input[type="submit"] { background-color: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; font-size: 16px; }
        input[type="submit"]:hover { background-color: #218838; }
        .message { padding: 15px; margin-top: 20px; border-radius: 4px; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .steps { margin-top: 20px; background-color: #eef; border: 1px solid #ccd; padding: 10px; font-size: 0.9em; }
        .steps pre { white-space: pre-wrap; word-wrap: break-word; margin: 0; }
        .warning { font-weight: bold; color: #856404; background-color: #fff3cd; border: 1px solid #ffeeba; padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; padding: 10px; margin-bottom: 15px; border-radius: 4px; }
    </style>
</head>
<body>

<div class="container">
    <h1>Yaks Kurulumu</h1>

     <div class="info">
        Yaks Kurulumu Için şu ZIP dosyasını indirecektir: <br><strong><?php echo htmlspecialchars($zipUrl); ?></strong><br>
        Ardından içindeki SQL dosyasını aşağıdaki veritabanına yükleyecek ve bir <code>knexfile.js</code> oluşturacaktır.
    </div>

    <div class="warning">
        <strong>DİKKAT:</strong> Bu araç veritabanı bilgilerinizi kullanır. Lütfen sadece güvenli ortamlarda çalıştırın. İşlem sonrası bu betiği sunucudan kaldırmanız veya erişimi kısıtlamanız şiddetle önerilir.
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="message error"><?php echo $errorMessage; ?></div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="message success"><?php echo $successMessage; ?></div>
    <?php endif; ?>

    <?php if (!empty($stepMessages)): ?>
        <div class="steps">
            <strong>İşlem Adımları:</strong>
            <pre><?php echo implode("\n", $stepMessages); ?></pre>
        </div>
    <?php endif; ?>

    <form action="" method="post">
        <h3>Veritabanı Bilgileri</h3>
        <div>
            <label for="db_host">Veritabanı Sunucusu (Host):</label>
            <input type="text" id="db_host" name="db_host" required placeholder="localhost" value="<?php echo isset($_POST['db_host']) ? htmlspecialchars($_POST['db_host']) : 'localhost'; ?>">
        </div>
        <div>
            <label for="db_user">Veritabanı Kullanıcı Adı:</label>
            <input type="text" id="db_user" name="db_user" required placeholder="root" value="<?php echo isset($_POST['db_user']) ? htmlspecialchars($_POST['db_user']) : ''; ?>">
        </div>
        <div>
            <label for="db_pass">Veritabanı Şifresi:</label>
            <input type="password" id="db_pass" name="db_pass" placeholder="Şifreniz yoksa boş bırakın">
        </div>
        <div>
            <label for="db_name">Veritabanı Adı:</label>
            <input type="text" id="db_name" name="db_name" required placeholder="veritabani_adi" value="<?php echo isset($_POST['db_name']) ? htmlspecialchars($_POST['db_name']) : ''; ?>">
        </div>
        <div>
            <input type="submit" value="İndir, Yükle ve Knexfile Oluştur">
        </div>
    </form>
</div>

</body>
</html>
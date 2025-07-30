<?php defined('BASEPATH') OR exit('No direct script access allowed');

class Clean extends CI_Controller {

	public function __construct()
    {
        parent::__construct();
        $this->simpegdb = $this->load->database('simpegdb',TRUE);
        $this->pdisdb = $this->load->database('pdisdb',TRUE);
        ini_set('max_execution_time', 7500); //300 seconds = 5 minutes
		ini_set('memory_limit', '-1');
    }


    public function index(){
        $employed  = $this->simpegdb->query("SELECT nip, idpersonal, nama 
            FROM tbpersonal 
            WHERE idstatus = '0'
            AND idkantor = '1'
            AND idpersonal  = '4597'
           
           
        ORDER BY nama asc ")->result();
        $counter = 0;
        foreach($employed as $row){
            $counter++;
            $this->RepairThisDataCuti($row->idpersonal, $row->nip);
        }
        ////echo $counter;

        /*

        UPDATE tbcuti a INNER JOIN tbpersonal b ON a.idpersonal =  b.idpersonal
SET a.hak_cuti = '0000' 
AND b.idkantor  = '1'
AND b.idstatus = '0'




        WHERE idpersonal  IN (
                '5005', 
                '4597', 
                '7311',
                '6717',
                '936'
            )
            */
    }

    public function RepairThisDataCuti($idpersonal, $nip){
        

        $hakdata =  $this->simpegdb->query("SELECT * FROM emp_cuti WHERE `lev_nip` = '$nip' ORDER BY lev_year ASC")->result();

        $hakCutiPerTahun = [];
        foreach($hakdata as $hakrow){
            $hakCutiPerTahun[$hakrow->lev_year] = $hakrow->lev_total;
        }

        $cutiDiambil = [];

        $list =  $this->simpegdb->query("SELECT * FROM tbcuti
            WHERE idpersonal = '$idpersonal' 
            AND idtipecuti = '1' 
            AND tglusulan BETWEEN '2015-01-01' AND '2025-09-01'
            ORDER BY tglusulan ASC ")->result();
        
        ////AND tglusulan BETWEEN '2021-01-01' AND '2025-08-18'

        foreach ($list as $row) {
            ///$cutiDiambil[$row->tglusulan] = $row->hak_cuti;
            $cutiDiambil[$row->idcuti] = array(
                'tglusulan' => $row->tglusulan,
                'hak_cuti'  => $row->hak_cuti
            );
        }

        // Jalankan fungsi untuk mengatur ulang hak cuti
        $hasilAkhir = $this->aturUlangHakCuti($cutiDiambil, $hakCutiPerTahun);
        echo "<hr>";
        // Tampilkan hasil
        echo "Hasil Pengaturan Ulang Hak Cuti: <br>";
        echo "Tanggal Cuti\t| Hak Cuti \t|ID  <br>";
        echo "----------------|---------|--------------<br>";
        foreach ($hasilAkhir as $item) {
            echo $item['tanggal'] . "\t| " . $item['hak']  . "\t| " . $item['id']. "<br>";
            $thishak = $item['hak'];
            $thisid = $item['id'];
            $this->simpegdb->query("UPDATE tbcuti SET hak_cuti = '$thishak' WHERE idcuti = '$thisid' ");
        }

        // Hitung jumlah cuti per tahun hak
        $jumlahPerTahun = [];
        foreach ($hasilAkhir as $item) {
            $tahun = $item['hak'];
            if (!isset($jumlahPerTahun[$tahun])) {
                $jumlahPerTahun[$tahun] = 0;
            }
            $jumlahPerTahun[$tahun]++;
        }

        echo "\nRekap Penggunaan Hak Cuti: <br>";
        foreach ($jumlahPerTahun as $tahun => $jumlah) {
            echo "Tahun Hak {$tahun}: {$jumlah} cuti (dari {$hakCutiPerTahun[$tahun]} hak)<br>";
        }

    }

    // Fungsi untuk menentukan periode berlaku hak cuti
    function getPeriodeHakCuti($tahun) {
        $periode = [
            'mulai' => ($tahun + 1) . '-01-01',
            'akhir' => ($tahun + 2) . '-06-30'
        ];
        
        // Khusus untuk hak cuti 2024, periode akhir sama dengan 2025-06-30
        if ($tahun == 2024) {
            $periode['akhir'] = '2026-06-30';
        }

        if ($tahun == 2018) {
            $periode['akhir'] = '2020-09-31';
        }
        

        return $periode;
    }

    // Fungsi untuk mengatur ulang hak cuti
    function aturUlangHakCuti($cutiDiambil, $hakCutiPerTahun) {
        $hasil = [];
        
        // Urutkan cuti berdasarkan tanggal
        ksort($cutiDiambil);

        foreach ($cutiDiambil as $idcuti => $tahunHak) {
            ////echo "<pre>";
            ////print_r($tahunHak['tglusulan']);   hak_cuti
            $tahunCuti = date('Y', strtotime($tahunHak['tglusulan']));
            $tahunYangTersedia = array_keys($hakCutiPerTahun);

            // Cari tahun hak cuti yang tersedia untuk tanggal ini
            $tahunHakYangCocok = null;

            // Cek apakah tahun hak asli masih valid
            $periode = $this->getPeriodeHakCuti($tahunHak['hak_cuti']);
            $tahun_hak = $tahunHak['hak_cuti'];
            if (strtotime($tahunHak['tglusulan']) >= strtotime($periode['mulai']) && 
                strtotime($tahunHak['tglusulan']) <= strtotime($periode['akhir'])) {

               //// echo $tahunHak['tglusulan']."=>".$tahunHak['hak_cuti']."<br>";

                // Hitung sudah berapa kali cuti diambil dari tahun hak ini
                $jumlahCutiDiambil = array_reduce($hasil, function($carry, $item) use ($tahun_hak) {
                    return $carry + ($item['hak'] == $tahun_hak ? 1 : 0);
                }, 0);
                
                if ($jumlahCutiDiambil < $hakCutiPerTahun[$tahunHak['hak_cuti']]) {
                    $tahunHakYangCocok = $tahunHak['hak_cuti'];
                }
            }

            // Jika tahun hak asli tidak valid atau sudah melebihi kuota
            if ($tahunHakYangCocok === null) {
                // Cari tahun hak lain yang tersedia untuk tanggal ini
                foreach ($tahunYangTersedia as $tahun) {
                    $periode = $this->getPeriodeHakCuti($tahun);
                    
                    if (strtotime($tahunHak['tglusulan']) >= strtotime($periode['mulai']) && 
                        strtotime($tahunHak['tglusulan']) <= strtotime($periode['akhir'])) {
                        
                        // Hitung sudah berapa kali cuti diambil dari tahun hak ini
                        $jumlahCutiDiambil = array_reduce($hasil, function($carry, $item) use ($tahun) {
                            return $carry + ($item['hak'] == $tahun ? 1 : 0);
                        }, 0);
                        
                        if ($jumlahCutiDiambil < $hakCutiPerTahun[$tahun]) {
                            $tahunHakYangCocok = $tahun;
                            break;
                        }
                    }
                }
            }

            if ($tahunHakYangCocok === null) {
                $tahunHakYangCocok = $tahunCuti;
                
                // Jika tahun cuti tidak ada dalam daftar hak cuti, gunakan tahun terdekat sebelumnya
                if (!isset($hakCutiPerTahun[$tahunHakYangCocok])) {
                    $tahunTersedia = array_keys($hakCutiPerTahun);
                    $tahunSebelumnya = max(array_filter($tahunTersedia, function($t) use ($tahunHakYangCocok) {
                        return $t < $tahunHakYangCocok;
                    }));
                    
                    if ($tahunSebelumnya) {
                        $tahunHakYangCocok = $tahunSebelumnya;
                    } else {
                        $tahunHakYangCocok = min($tahunTersedia);
                    }
                }
            }

            $hasil[] = [
                'tanggal' => $tahunHak['tglusulan'],
                'hak' => $tahunHakYangCocok, 
                'id'    => $idcuti
            ];  
        }

        return $hasil;
        
    }


    public function DeleteDoubleData(){
        /*$sql = "SELECT a.idpersonal, a.tglusulan, b.nama
            FROM tbcuti_maintenance a INNER JOIN tbpersonal b ON a.idpersonal =  b.idpersonal 
            WHERE b.idstatus = '0' 
            AND b.idkantor = '1'
            AND a.idstatus = '1'
            GROUP BY a.idpersonal, a.tglusulan
            HAVING COUNT(*) > 1
            ORDER BY a.tglusulan ASC  ";
        */


        /*
        DELETE t1 FROM tbcuti_maintenance t1
            INNER JOIN tbcuti_maintenance t2
            INNER JOIN tbpersonal ON t1.idpersonal =  tbpersonal.idpersonal
            WHERE t1.idpersonal = t2.idpersonal 
            AND t1.tglusulan = t2.tglusulan 
            AND t1.idcuti > t2.idcuti
            AND tbpersonal.idkantor = '1'
            AND tbpersonal.idstatus = '0'
            AND tbpersonal.idpersonal IN (
            '3630',
'3115',
'7788'
            )
            */

       /*
       SELECT a.hak_cuti , COUNT(a.idcuti) as ttl , b.nip, b.nama, b.idpersonal as idp
FROM tbcuti_maintenance a INNER JOIN tbpersonal b ON a.idpersonal = b.idpersonal
WHERE b.idstatus = '0'
AND b.idkantor  = '1'
AND a.hak_cuti = '2020'
AND a.idtipecuti = '1'
GROUP BY a.idpersonal, a.hak_cuti 
ORDER BY ttl DESC 
*/
    }

}

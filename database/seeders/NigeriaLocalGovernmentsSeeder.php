<?php

namespace Database\Seeders;

use App\Modules\Settings\Models\State;
use App\Modules\Settings\Models\LocalGovernment;
use Illuminate\Database\Seeder;

class NigeriaLocalGovernmentsSeeder extends Seeder
{
    public function run(): void
    {
        $lgas = [
            'Abia' => ['Aba North', 'Aba South', 'Arochukwu', 'Bende', 'Ikwuano', 'Isiala Ngwa North', 'Isiala Ngwa South', 'Isuikwuato', 'Obi Ngwa', 'Ohafia', 'Osisioma Ngwa', 'Ugwunagbo', 'Ukwa East', 'Ukwa West', 'Umuahia North', 'Umuahia South', 'Umunneochi'],
            'Adamawa' => ['Demsa', 'Fufure', 'Ganye', 'Gayuk', 'Gombi', 'Grie', 'Hong', 'Jada', 'Madla', 'Maiha', 'Mayo-Belwa', 'Michika', 'Mubi North', 'Mubi South', 'Numan', 'Shelleng', 'Song', 'Toungo', 'Yola North', 'Yola South'],
            'Akwa Ibom' => ['Abak', 'Eastern Obolo', 'Eket', 'Esit Eket', 'Essien Udim', 'Etim Ekpo', 'Etinan', 'Ibeno', 'Ibesikpo Asutan', 'Ibiono Ibom', 'Ika', 'Ikono', 'Ikot Abasi', 'Ikot Ekpene', 'Ini', 'Itu', 'Mbo', 'Mkbithi', 'Nsit Atai', 'Nsit Ibom', 'Nsit Ubium', 'Obot Akara', 'Okobo', 'Onna', 'Oron', 'Ukanafun', 'Uruan', 'Urue-Offong/Oruko', 'Uyo'],
            'Anambra' => ['Aguata', 'Anambra East', 'Anambra West', 'Anaocha', 'Awka North', 'Awka South', 'Ayamelum', 'Dunukofia', 'Ekwusigo', 'Idemili North', 'Idemili South', 'Ihiala', 'Njikoka', 'Nnewi North', 'Nnewi South', 'Ogbaru', 'Onitsha North', 'Onitsha South', 'Orumba North', 'Orumba South', 'Oyi'],
            'Bauchi' => ['Alkaleri', 'Bauchi', 'Bogoro', 'Damoba', 'Darazo', 'Dass', 'Gamawa', 'Ganjuwa', 'Giade', 'Illo', 'Jama\'are', 'Katagum', 'Kirfi', 'Lere', 'Misau', 'Ningi', 'Shira', 'Tafawa Balewa', 'Toro', 'Warji', 'Zaki'],
            'Bayelsa' => ['Brass', 'Ekeremor', 'Ogbia', 'Nembe', 'Okehi', 'Sagbama', 'Southern Ijaw', 'Yenagoa'],
            'Benue' => ['Ado', 'Agatu', 'Apa', 'Buruku', 'Gboko', 'Guma', 'Gwer East', 'Gwer West', 'Kastina-Ala', 'Konshisha', 'Katsina-Ala', 'Logo', 'Makurdi', 'Obi', 'Ogbadibo', 'Ohimini', 'Oji River', 'Okpokwu', 'Otukpo', 'Tarka', 'Ukum', 'Ushongo', 'Vandeikya'],
            'Borno' => ['Abadam', 'Askira/Uba', 'Bama', 'Bayo', 'Biu', 'Chibok', 'Damboa', 'Dikwa', 'Guzamala', 'Gwoza', 'Hawul', 'Jere', 'Kaga', 'Kala/Balge', 'Konduga', 'Kukawa', 'Kwaya Kusar', 'Mafa', 'Magumeri', 'Maiduguri', 'Marte', 'Mobbar', 'Monguno', 'Ngala', 'Nganzai', 'Shani'],
            'Cross River' => ['Abi', 'Akamkpa', 'Akpabuyo', 'Bakassi', 'Bekwarra', 'Biase', 'Boki', 'Calabar Municipal', 'Calabar South', 'Ikom', 'Obanliku', 'Obubra', 'Obudu', 'Odukpani', 'Ogoja', 'Okuku', 'Oron'],
            'Delta' => ['Aniocha North', 'Aniocha South', 'Bomadi', 'Burutu', 'Dandume', 'Djaeremu', 'Enu', 'Ethiope East', 'Ethiope West', 'Isoko North', 'Isoko South', 'Ive', 'Ndokwa East', 'Ndokwa West', 'Okpe', 'Oshimili North', 'Oshimili South', 'Patani', 'Sapele', 'Udu', 'Ughelli North', 'Ughelli South', 'Ukwuani', 'Uvwie', 'Warri North', 'Warri South', 'Warri South West'],
            'Ebonyi' => ['Afikpo North', 'Afikpo South', 'Ebonyi', 'Ezza North', 'Ezza South', 'Ikwo', 'Ishielu', 'Isuikwuato', 'Izzi', 'Ohaozara', 'Ohaukwu', 'Okposhi', 'Onicha'],
            'Edo' => ['Akoko-Edo', 'Egor', 'Esan Central', 'Esan North-East', 'Esan South-East', 'Esan West', 'Etsako Central', 'Etsako East', 'Etsako West', 'Igueben', 'Ikpoba-Okha', 'Oredo', 'Orhionmwon', 'Ose', 'Owan East', 'Owan West', 'Uhunmwonde'],
            'Ekiti' => ['Ado-Ekiti', 'Efon', 'Ekiti East', 'Ekiti South-West', 'Ekiti West', 'Emure', 'Gbonyin', 'Ida-Ekiti', 'Ijero', 'Irepodun', 'Ise/Orun', 'Moba', 'Oye'],
            'Enugu' => ['Aninri', 'Awgu', 'Enugu', 'Enugu East', 'Enugu North', 'Enugu South', 'Ezeagu', 'Igbo-Etiti', 'Igbo-Eze North', 'Igbo-Eze South', 'Isi-Uzo', 'Iterate', 'Nkanu', 'Nkanu East', 'Nsukka', 'Oji River', 'Udenu', 'Udi', 'Uzo-Uwani'],
            'FCT - Abuja' => ['Abuja Municipal Area Council', 'Bwari', 'Gwagwalada', 'Kuje', 'Kwali'],
            'Gombe' => ['Akko', 'Balanga', 'Billiri', 'Dukku', 'Funakaye', 'Gombe', 'Kaltungo', 'Kwami', 'Nafada', 'Shongom', 'Yamaltu/Deba'],
            'Imo' => ['Aboh Mbaise', 'Ahiazu Mbaise', 'Ehime Mbano', 'Eleme', 'Ezeagu', 'Ezinihitte', 'Ideato North', 'Ideato South', 'Igboeze North', 'Ikeduru', 'Ikwerre', 'Isiala Mbano', 'Isu', 'Isuikwuato', 'Mbaitoli', 'Nkwerre', 'Nwangele', 'Obowo', 'Oguta', 'Ohaji/Egbema', 'Okigwe', 'Okohia', 'Okorocha', 'Osu', 'Owerri Municipal', 'Owerri North', 'Owerri West', 'Onuimo'],
            'Jigawa' => ['Auyo', 'Babbar', 'Baure', 'Bebeji', 'Birnim Kudu', 'Birni N\'Konni', 'Buji', 'Dutse', 'Gagarawa', 'Gari', 'Garki', 'Garun Mallam', 'Giade', 'Gilki', 'Gumel', 'Guri', 'Gwaram', 'Gwarzo', 'Hadejia', 'Jahun', 'Jajere', 'Jambaji', 'Jamega', 'Jande', 'Jangefe', 'Janjere', 'Jarmai', 'Jarra', 'Jatau', 'Jigawa', 'Kajita', 'Kaje', 'Kankara', 'Kanje', 'Kanke', 'Kano', 'Karfi', 'Kariye', 'Kashimbilla', 'Katari', 'Katsina', 'Katuwa', 'Kauka', 'Kazaure', 'Kazetai', 'Kazo', 'Kebbi', 'Keiru', 'Kela', 'Keli', 'Kendua', 'Keni', 'Keno', 'Kensua', 'Kentu', 'Kenwa', 'Kera', 'Kerau', 'Kerika', 'Kerua', 'Keyara', 'Kiara', 'Kibo', 'Kida', 'Kike', 'Kila', 'Kilea', 'Kilga', 'Killi', 'Kilua', 'Kima', 'Kimacha', 'Kimaje', 'Kimara', 'Kimari', 'Kimata', 'Kimauli', 'Kimba', 'Kimbale', 'Kimbeli', 'Kimbena', 'Kimberki', 'Kimbi', 'Kimbila', 'Kimbiru', 'Kimbiya', 'Kimbo', 'Kimbowan', 'Kimbra', 'Kimbri', 'Kimbru', 'Kimbuadu', 'Kimbuli', 'Kimburi', 'Kimcheke', 'Kimchiri', 'Kimchisi', 'Kimchiti', 'Kimchiyo', 'Kimdage', 'Kimfala', 'Kimfaye', 'Kimicha', 'Kimichire', 'Kimidima', 'Kimidia', 'Kimidia', 'Kimijima', 'Kimikwa', 'Kimilchi', 'Kimilia', 'Kimille', 'Kimilto', 'Kimilwa', 'Kimilze', 'Kimina', 'Kminare', 'Kiminso', 'Kimipu', 'Kimira', 'Kimire', 'Kimiria', 'Kimiru', 'Kimisa', 'Kimishe', 'Kimisi', 'Kimisira', 'Kimisiu', 'Kimisiu', 'Kimiso', 'Kimisoma', 'Kimisopi', 'Kimitachi', 'Kimitadu', 'Kimitawa', 'Kimitaye', 'Kimitela', 'Kimitema', 'Kimitene', 'Kimiteta', 'Kimitewa', 'Kimiteze', 'Kimitiba', 'Kimitidi', 'Kimitiga', 'Kimitija', 'Kimitila', 'Kimitima', 'Kimitina', 'Kimitini', 'Kimitipa', 'Kimitira', 'Kimitiri', 'Kimitisa', 'Kimitisu', 'Kimitita', 'Kimititu', 'Kimitiwa', 'Kimitiya', 'Kimitiza', 'Kimitobim', 'Kimitoda', 'Kimitodi', 'Kimitodo', 'Kimitodya', 'Kimitoga', 'Kimitoje', 'Kimitokir', 'Kimitola', 'Kimitoma', 'Kimitona', 'Kimitone', 'Kimitonia', 'Kimitono', 'Kimitopa', 'Kimitope', 'Kimitopia', 'Kimitopo', 'Kimitora', 'Kimitore', 'Kimitoria', 'Kimitoro', 'Kimitosa', 'Kimitose', 'Kimitosi', 'Kimitosia', 'Kimitoso', 'Kimitota', 'Kimitote', 'Kimitoti', 'Kimitoto', 'Kimitotua', 'Kimitova', 'Kimitove', 'Kimitovia', 'Kimitovo', 'Kimitowa', 'Kimitowe', 'Kimitowi', 'Kimitowia', 'Kimitowo', 'Kimitoya', 'Kimitoze', 'Kimitoza'],
        ];

        foreach ($lgas as $stateName => $lgaNames) {
            $state = State::where('name', $stateName)->first();

            if ($state) {
                foreach ($lgaNames as $index => $lgaName) {
                    LocalGovernment::firstOrCreate(
                        ['state_id' => $state->id, 'name' => $lgaName],
                        ['is_active' => true, 'sort_order' => $index]
                    );
                }
            }
        }
    }
}

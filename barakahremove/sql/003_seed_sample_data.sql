INSERT INTO italy_comuni
(istat_code, comune_name, comune_name_normalized, province_code, province_name, region_code, region_name, cadastral_code, cap, is_active, source_name)
VALUES
('001272','Torino','torino','TO','Torino','01','Piemonte','L219','10121',1,'sample'),
('015146','Milano','milano','MI','Milano','03','Lombardia','F205','20121',1,'sample'),
('058091','Roma','roma','RM','Roma','12','Lazio','H501','00118',1,'sample'),
('021008','Bolzano/Bozen','bolzano bozen','BZ','Bolzano/Bozen','04','Trentino-Alto Adige/Südtirol','A952','39100',1,'sample'),
('037006','Bologna','bologna','BO','Bologna','08','Emilia-Romagna','A944','40121',1,'sample')
ON DUPLICATE KEY UPDATE
comune_name = VALUES(comune_name),
comune_name_normalized = VALUES(comune_name_normalized),
province_code = VALUES(province_code),
province_name = VALUES(province_name),
region_code = VALUES(region_code),
region_name = VALUES(region_name),
cadastral_code = VALUES(cadastral_code),
cap = VALUES(cap),
is_active = VALUES(is_active),
source_name = VALUES(source_name);

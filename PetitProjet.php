<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Abidjan');

$db = new PDO('sqlite:' . __DIR__ . '/affou_delice.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec("
CREATE TABLE IF NOT EXISTS plats (
 id INTEGER PRIMARY KEY AUTOINCREMENT, nom TEXT NOT NULL, categorie TEXT NOT NULL,
 prix INTEGER NOT NULL CHECK(prix >= 0), disponible INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS commandes (
 id INTEGER PRIMARY KEY AUTOINCREMENT, client TEXT NOT NULL DEFAULT '',
 service TEXT NOT NULL, table_no INTEGER, statut TEXT NOT NULL DEFAULT 'En attente',
 payee INTEGER NOT NULL DEFAULT 0, cree_le TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS articles (
 id INTEGER PRIMARY KEY AUTOINCREMENT, commande_id INTEGER NOT NULL REFERENCES commandes(id),
 nom TEXT NOT NULL, quantite INTEGER NOT NULL, prix INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS reservations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, client TEXT NOT NULL, telephone TEXT NOT NULL DEFAULT '',
 personnes INTEGER NOT NULL, table_no INTEGER NOT NULL, date_reservation TEXT NOT NULL
);
");

if ((int)$db->query('SELECT COUNT(*) FROM plats')->fetchColumn() === 0) {
    $ajout = $db->prepare('INSERT INTO plats(nom,categorie,prix) VALUES(?,?,?)');
    foreach ([['Poulet braisé','Plats',3500],['Attiéké poisson','Plats',3000],
        ['Riz sauce arachide','Plats',2000],['Jus de bissap','Boissons',700]] as $plat) {
        $ajout->execute($plat);
    }
}
if (empty($_SESSION['token'])) $_SESSION['token'] = bin2hex(random_bytes(32));

function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fcfa(int $n): string { return number_format($n, 0, ',', ' ') . ' FCFA'; }
function retour(): never { header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$erreur = '';
$statuts = ['En attente','En préparation','Prête','Servie','Annulée'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['token'], (string)($_POST['token'] ?? ''))) {
        http_response_code(403); exit('Formulaire expiré. Rechargez la page.');
    }
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'plat') {
            $nom = trim((string)($_POST['nom'] ?? ''));
            $cat = trim((string)($_POST['categorie'] ?? ''));
            $prix = filter_var($_POST['prix'] ?? null, FILTER_VALIDATE_INT);
            if ($nom === '' || $cat === '' || $prix === false || $prix < 0) throw new InvalidArgumentException('Vérifiez le nom, la catégorie et le prix.');
            $s = $db->prepare('INSERT INTO plats(nom,categorie,prix) VALUES(?,?,?)');
            $s->execute([$nom,$cat,$prix]);
            $_SESSION['message'] = 'Plat ajouté au menu.'; retour();
        } elseif ($action === 'disponibilite') {
            $s = $db->prepare('UPDATE plats SET disponible=1-disponible WHERE id=?');
            $s->execute([(int)($_POST['id'] ?? 0)]);
            $_SESSION['message'] = 'Disponibilité modifiée.'; retour();
        } elseif ($action === 'commande') {
            $service = (string)($_POST['service'] ?? '');
            $table = filter_var($_POST['table_no'] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($service, ['Sur place','À emporter'], true)) throw new InvalidArgumentException('Mode de service invalide.');
            if ($service === 'Sur place' && ($table === false || $table < 1 || $table > 20)) throw new InvalidArgumentException('Choisissez une table valide.');
            $quantites = $_POST['qte'] ?? [];
            if (!is_array($quantites)) throw new InvalidArgumentException('Commande invalide.');
            $choix = [];
            foreach ($quantites as $id => $qte) {
                $id = filter_var($id, FILTER_VALIDATE_INT); $qte = filter_var($qte, FILTER_VALIDATE_INT);
                if ($id === false || $qte === false || $qte < 0 || $qte > 50) throw new InvalidArgumentException('Quantité invalide.');
                if (!$qte) continue;
                $s = $db->prepare('SELECT nom,prix FROM plats WHERE id=? AND disponible=1');
                $s->execute([$id]); $p = $s->fetch();
                if (!$p) throw new InvalidArgumentException('Un plat sélectionné est indisponible.');
                $choix[] = [$p['nom'],$qte,(int)$p['prix']];
            }
            if (!$choix) throw new InvalidArgumentException('Choisissez au moins un plat.');
            $db->beginTransaction();
            $s = $db->prepare('INSERT INTO commandes(client,service,table_no,cree_le) VALUES(?,?,?,?)');
            $s->execute([trim((string)($_POST['client'] ?? '')),$service,$service === 'Sur place' ? $table : null,date('Y-m-d H:i:s')]);
            $idCommande = (int)$db->lastInsertId();
            $item = $db->prepare('INSERT INTO articles(commande_id,nom,quantite,prix) VALUES(?,?,?,?)');
            foreach ($choix as [$nom,$qte,$prix]) $item->execute([$idCommande,$nom,$qte,$prix]);
            $db->commit();
            $_SESSION['message'] = "Commande #$idCommande enregistrée."; retour();
        } elseif ($action === 'statut') {
            $statut = (string)($_POST['statut'] ?? '');
            if (!in_array($statut,$statuts,true)) throw new InvalidArgumentException('Statut invalide.');
            $s = $db->prepare('UPDATE commandes SET statut=? WHERE id=?');
            $s->execute([$statut,(int)($_POST['id'] ?? 0)]);
            $_SESSION['message'] = 'Statut mis à jour.'; retour();
        } elseif ($action === 'payer') {
            $s = $db->prepare("UPDATE commandes SET payee=1 WHERE id=? AND statut!='Annulée'");
            $s->execute([(int)($_POST['id'] ?? 0)]);
            $_SESSION['message'] = $s->rowCount() ? 'Paiement enregistré.' : 'Impossible d’encaisser une commande annulée.'; retour();
        } elseif ($action === 'reservation') {
            $client = trim((string)($_POST['client'] ?? ''));
            $telephone = trim((string)($_POST['telephone'] ?? ''));
            $personnes = filter_var($_POST['personnes'] ?? null, FILTER_VALIDATE_INT);
            $table = filter_var($_POST['table_no'] ?? null, FILTER_VALIDATE_INT);
            $date = (string)($_POST['date_reservation'] ?? '');
            $parsed = DateTime::createFromFormat('Y-m-d\TH:i', $date);
            if ($client === '' || $personnes === false || $personnes < 1 || $personnes > 30 ||
                $table === false || $table < 1 || $table > 20 || !$parsed || $parsed->format('Y-m-d\TH:i') !== $date) {
                throw new InvalidArgumentException('Vérifiez le nom, le nombre de personnes, la table et la date.');
            }
            $s = $db->prepare('INSERT INTO reservations(client,telephone,personnes,table_no,date_reservation) VALUES(?,?,?,?,?)');
            $s->execute([$client,$telephone,$personnes,$table,$parsed->format('Y-m-d H:i:s')]);
            $_SESSION['message'] = 'Réservation enregistrée.'; retour();
        } elseif ($action === 'supprimer_reservation') {
            $s = $db->prepare('DELETE FROM reservations WHERE id=?');
            $s->execute([(int)($_POST['id'] ?? 0)]);
            $_SESSION['message'] = 'Réservation supprimée.'; retour();
        }
    } catch (InvalidArgumentException $ex) { $erreur = $ex->getMessage(); }
}

$plats = $db->query('SELECT * FROM plats ORDER BY categorie,nom')->fetchAll();
$commandes = $db->query("SELECT c.*,COALESCE(SUM(a.quantite*a.prix),0) total,
 COALESCE(SUM(a.quantite),0) nb FROM commandes c LEFT JOIN articles a ON a.commande_id=c.id
 GROUP BY c.id ORDER BY c.id DESC LIMIT 30")->fetchAll();
$reservations = $db->query('SELECT * FROM reservations ORDER BY date_reservation LIMIT 30')->fetchAll();
$s = $db->prepare("SELECT COUNT(*) nb,COALESCE(SUM((SELECT SUM(a.quantite*a.prix) FROM articles a WHERE a.commande_id=c.id)),0) total
 FROM commandes c WHERE c.payee=1 AND substr(c.cree_le,1,10)=?");
$s->execute([date('Y-m-d')]); $stats = $s->fetch();
$aTraiter = (int)$db->query("SELECT COUNT(*) FROM commandes WHERE statut IN ('En attente','En préparation')")->fetchColumn();
$token = e($_SESSION['token']);
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Affou Délice — Gestion du restaurant</title>
<style>
:root{--vert:#246b45;--fond:#f5f6ef;--texte:#26342b;--ligne:#e5e9df;--gris:#748078}
*{box-sizing:border-box}body{margin:0;background:var(--fond);color:var(--texte);font:15px/1.5 system-ui,Segoe UI,sans-serif}
header{background:#173d2b;color:white;padding:19px max(20px,calc((100% - 1120px)/2));display:flex;justify-content:space-between;gap:12px}
header strong{font-size:22px}header span{color:#d8e6d9}main{max-width:1120px;margin:26px auto;padding:0 18px}
h1{font-size:27px;margin:0}h2{font-size:19px;margin:0 0 14px}.muted{color:var(--gris)}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin-bottom:16px}.box,.card{background:white;border:1px solid var(--ligne);border-radius:13px;padding:17px}
.box small{color:var(--gris)}.box b{display:block;font-size:22px}.layout{display:grid;grid-template-columns:1fr 1fr;gap:15px}.wide{grid-column:1/-1}
label{display:block;font-size:13px;font-weight:650;margin-bottom:4px}input,select{width:100%;padding:9px 10px;border:1px solid #dce1d7;border-radius:8px;font:inherit;background:white}
button,.btn{border:0;border-radius:8px;background:var(--vert);color:white;padding:9px 13px;font:inherit;font-weight:650;cursor:pointer;text-decoration:none;display:inline-block}
.light{background:#edf4e8!important;color:var(--vert)!important}.danger{background:#fff0eb!important;color:#9a4328!important}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.full{grid-column:1/-1}.dish{display:grid;grid-template-columns:1fr auto 70px;gap:9px;align-items:center;padding:8px 0;border-bottom:1px solid var(--ligne)}
.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:540px}td,th{text-align:left;padding:9px;border-bottom:1px solid var(--ligne);vertical-align:middle}th{font-size:12px;color:var(--gris)}
td form{display:flex;gap:5px;align-items:center}td select{min-width:125px;padding:6px}.msg{padding:10px 13px;border-radius:8px;margin-bottom:14px;background:#e8f5e8;color:#20532e}.err{background:#fff0eb;color:#913e25}
.reservation{padding:10px 0;border-bottom:1px solid var(--ligne)}footer{text-align:center;color:var(--gris);padding:20px}
@media(max-width:720px){.layout{grid-template-columns:1fr}.wide{grid-column:auto}.stats{grid-template-columns:1fr}.top,header{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr}.full{grid-column:auto}}
</style></head>
<body>
<header><strong>🍽 Affou Délice</strong><span>Gestion simple de votre restaurant</span></header>
<main>
<div class="top"><div><h1>Bonjour, bienvenue 👋</h1><span class="muted">Activité du <?=e(date('d/m/Y'))?></span></div><a class="btn light" href="#commande">＋ Nouvelle commande</a></div>
<?php if($message):?><div class="msg"><?=e($message)?></div><?php endif?>
<?php if($erreur):?><div class="msg err"><?=e($erreur)?></div><?php endif?>
<section class="stats"><div class="box"><small>Ventes encaissées aujourd’hui</small><b><?=e(fcfa((int)$stats['total']))?></b></div><div class="box"><small>Commandes payées aujourd’hui</small><b><?=e((int)$stats['nb'])?></b></div><div class="box"><small>Commandes à traiter</small><b><?=e($aTraiter)?></b></div></section>
<div class="layout">
<section class="card" id="commande"><h2>Nouvelle commande</h2>
<form method="post"><input type="hidden" name="token" value="<?=$token?>"><input type="hidden" name="action" value="commande">
<div class="grid"><div><label>Nom du client (facultatif)</label><input name="client" maxlength="100" placeholder="Nom du client"></div>
<div><label>Service</label><select name="service" id="service" onchange="document.getElementById('tableCommande').hidden=this.value!=='Sur place'"><option>Sur place</option><option>À emporter</option></select></div>
<div id="tableCommande"><label>Table</label><select name="table_no"><?php for($i=1;$i<=20;$i++):?><option value="<?=$i?>">Table <?=$i?></option><?php endfor?></select></div></div>
<p class="muted">Saisissez les quantités :</p>
<?php foreach($plats as $p):if(!$p['disponible'])continue?><div class="dish"><span><b><?=e($p['nom'])?></b><br><small class="muted"><?=e($p['categorie'])?></small></span><span><?=e(fcfa((int)$p['prix']))?></span><input aria-label="Quantité <?=e($p['nom'])?>" type="number" min="0" max="50" name="qte[<?=(int)$p['id']?>]" value="0"></div><?php endforeach?>
<button style="margin-top:13px">Enregistrer la commande</button></form></section>

<section class="card" id="menu"><h2>Ajouter un plat</h2><form method="post" class="grid">
<input type="hidden" name="token" value="<?=$token?>"><input type="hidden" name="action" value="plat">
<div><label>Nom du plat</label><input name="nom" maxlength="100" required></div><div><label>Catégorie</label><input name="categorie" maxlength="60" placeholder="Plats, Boissons…" required></div>
<div><label>Prix en FCFA</label><input type="number" name="prix" min="0" required></div><div style="align-self:end"><button>Ajouter au menu</button></div></form>
<h2 style="margin-top:22px">Menu actuel</h2><div class="scroll"><table><thead><tr><th>Plat</th><th>Catégorie</th><th>Prix</th><th></th></tr></thead><tbody>
<?php foreach($plats as $p):?><tr><td><?=e($p['nom'])?></td><td><?=e($p['categorie'])?></td><td><?=e(fcfa((int)$p['prix']))?></td><td><form method="post"><input type="hidden" name="token" value="<?=$token?>"><input type="hidden" name="action" value="disponibilite"><input type="hidden" name="id" value="<?=(int)$p['id']?>"><button class="<?=$p['disponible']?'light':'danger'?>"><?=$p['disponible']?'Disponible':'Indisponible'?></button></form></td></tr><?php endforeach?>
</tbody></table></div></section>

<section class="card wide"><h2>Commandes récentes</h2><div class="scroll"><table><thead><tr><th>Commande</th><th>Client / service</th><th>Articles</th><th>Total</th><th>État</th><th>Paiement</th></tr></thead><tbody>
<?php if(!$commandes):?><tr><td colspan="6">Aucune commande enregistrée.</td></tr><?php endif?>
<?php foreach($commandes as $c):?><tr><td><b>#<?=(int)$c['id']?></b><br><small class="muted"><?=e(date('d/m H:i',strtotime($c['cree_le'])))?></small></td>
<td><?=e($c['client']?:'Client')?><br><small class="muted"><?=e($c['service'])?><?=$c['table_no']?' · Table '.(int)$c['table_no']:''?></small></td>
<td><?=(int)$c['nb']?></td><td><?=e(fcfa((int)$c['total']))?></td><td><form method="post"><input type="hidden" name="token" value="<?=$token?>"><input type="hidden" name="action" value="statut"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><select name="statut"><?php foreach($statuts as $st):?><option <?=($c['statut']===$st?'selected':'')?>><?=e($st)?></option><?php endforeach?></select><button class="light">OK</button></form></td>
<td><?php if($c['payee']):?>Payée<?php elseif($c['statut']!=='Annulée'):?><form method="post"><input type="hidden" name="token" value="<?=$token?>"><input type="hidden" name="action" value="payer"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><button>Encaisser</button></form><?php else:?>Annulée<?php endif?></td></tr><?php endforeach?>
</tbody></table></div></section>

<section class="card"><h2>Nouvelle réservation</h2><form method="post" class="grid">
<input type="hidden" name="token" value="<?=$token?>"><input type="hidden" name="action" value="reservation">
<div><label>Nom du client</label><input name="client" maxlength="100" required></div><div><label>Téléphone (facultatif)</label><input name="telephone" type="tel" maxlength="30"></div>
<div><label>Nombre de personnes</label><input name="personnes" type="number" min="1" max="30" value="2" required></div><div><label>Table</label><select name="table_no"><?php for($i=1;$i<=20;$i++):?><option value="<?=$i?>">Table <?=$i?></option><?php endfor?></select></div>
<div class="full"><label>Date et heure</label><input name="date_reservation" type="datetime-local" value="<?=e(date('Y-m-d\TH:i',strtotime('+1 hour')))?>" required></div><div class="full"><button>Enregistrer la réservation</button></div></form></section>
<section class="card"><h2>Réservations à venir</h2><?php if(!$reservations):?><p class="muted">Aucune réservation.</p><?php endif?>
<?php foreach($reservations as $r):?><div class="reservation"><b><?=e($r['client'])?></b><br><span class="muted"><?=e(date('d/m/Y à H:i',strtotime($r['date_reservation'])))?> · <?=(int)$r['personnes']?> personne(s) · Table <?=(int)$r['table_no']?><?=($r['telephone']?' · '.e($r['telephone']):'')?></span>
<form method="post" onsubmit="return confirm('Supprimer cette réservation ?')"><input type="hidden" name="token" value="<?=$token?>"><input type="hidden" name="action" value="supprimer_reservation"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="danger">Supprimer</button></form></div><?php endforeach?></section>
</div></main><footer>Affou Délice · Données enregistrées sur cet ordinateur.</footer></body></html>

<?php
/** Aplica (ou, com --desfazer, remove) a escolha de painel na tela de login do painel clássico. Uso: php aplicar.php /var/www/html [--desfazer] */
$dir = rtrim($argv[1] ?? '/var/www/html', '/');
$undo = in_array('--desfazer', $argv, true);
$theme = $dir . '/themes/prisma_v5/_common/login.tpl';
$index = $dir . '/index.php';
foreach ([$theme, $index] as $f) if (!is_file($f)) { fwrite(STDERR, "Arquivo não encontrado: $f\n"); exit(1); }

$M1 = '<!-- IPBX-PAINEL-SELETOR -->'; $M2 = '<!-- /IPBX-PAINEL-SELETOR -->';
$P1 = '/* IPBX-PAINEL-SELETOR */'; $P2 = '/* /IPBX-PAINEL-SELETOR */';

$html = <<<'H'
<!-- IPBX-PAINEL-SELETOR -->
				<div class="form-group" id="ipbx-painel-sel" style="display:flex;gap:8px">
					<label style="flex:1;margin:0;cursor:pointer">
						<input type="radio" name="painel" value="novo" style="position:absolute;opacity:0">
						<span class="ipbx-pn" style="display:block;text-align:center;padding:10px 6px;border:1px solid #475569;border-radius:6px;color:#cbd5e1;font-size:13px;white-space:nowrap">Painel Novo 6.0</span>
					</label>
					<label style="flex:1;margin:0;cursor:pointer">
						<input type="radio" name="painel" value="classico" checked style="position:absolute;opacity:0">
						<span class="ipbx-pn" style="display:block;text-align:center;padding:10px 6px;border:1px solid #475569;border-radius:6px;color:#cbd5e1;font-size:13px;white-space:nowrap">Painel Clássico</span>
					</label>
				</div>
				{literal}
				<style>#ipbx-painel-sel input:checked + .ipbx-pn{background:linear-gradient(135deg,#7c3aed,#6366f1);border-color:#7c3aed;color:#fff;font-weight:700}</style>
				<script>
				(function(){
					try {
						var m = document.cookie.match(/(?:^|; )ipbx_painel=([^;]+)/);
						if (m) { var r = document.querySelector('#ipbx-painel-sel input[value="' + m[1] + '"]'); if (r) r.checked = true; }
						document.getElementById('form_login').addEventListener('submit', function(){
							var c = document.querySelector('#ipbx-painel-sel input:checked');
							if (c) document.cookie = 'ipbx_painel=' + c.value + '; path=/; max-age=31536000; SameSite=Lax';
						});
					} catch (e) {}
				})();
				</script>
				{/literal}
				<!-- /IPBX-PAINEL-SELETOR -->

H;
$E1 = '/* IPBX-PAINEL-ENTRADA */'; $E2 = '/* /IPBX-PAINEL-ENTRADA */';
$entrada = "    $E1\n    if (\$_SERVER['REQUEST_METHOD'] === 'GET' && !isset(\$_GET['menu'])) {\n        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache');\n        if (!isset(\$_GET['classico']) && (\$_COOKIE['ipbx_painel'] ?? '') === 'novo' && is_file(__DIR__ . '/seletor.php')) { header('Location: seletor.php?ir=novo'); exit; }\n    }\n    $E2\n";
$php = "    $P1\n    if (in_array((\$_POST['painel'] ?? ''), ['novo', 'classico'], true) && !headers_sent()) setcookie('ipbx_painel', \$_POST['painel'], time() + 86400 * 365, '/', '', false, true);\n    if ((\$_POST['painel'] ?? '') === 'novo' && is_file(__DIR__ . '/seletor.php')) return 'seletor.php?ir=novo';\n    $P2\n";

$tpl = file_get_contents($theme); $idx = file_get_contents($index);
if ($undo) {
    $tpl = preg_replace('#\s*' . preg_quote($M1, '#') . '.*?' . preg_quote($M2, '#') . '\s*#s', "\n\n\t\t\t\t", $tpl, 1);
    $idx = preg_replace('#\n[ \t]*' . preg_quote($E1, '#') . '.*?' . preg_quote($E2, '#') . '[ \t]*\n#s', "\n", $idx, 1);
    $idx = preg_replace('#\n[ \t]*' . preg_quote($P1, '#') . '.*?' . preg_quote($P2, '#') . '[ \t]*\n#s', "\n", $idx, 1);
} else {
    /* Login novo já traz a escolha de painel (name="painel"); só o tema antigo recebe o bloco. */
    if (strpos($tpl, $M1) === false && strpos($tpl, 'name="painel"') === false) {
        if (preg_match('#<div class="form-group">\s*<button type="submit"#', $tpl, $m, PREG_OFFSET_CAPTURE)) {
            $tpl = substr_replace($tpl, $html . "\t\t\t\t", $m[0][1], 0);
        } else { fwrite(STDERR, "Aviso: ponto de inserção no login.tpl não encontrado; seguindo só com o index.php.\n"); }
    }
    $idx = preg_replace('#\n[ \t]*' . preg_quote($E1, '#') . '.*?' . preg_quote($E2, '#') . '[ \t]*\n#s', "\n", $idx, 1);
    if (strpos($idx, $E1) === false) {
        if (!preg_match('#(\$postLogin = get_post_login_redirect\(\$pACL, \$_SESSION\[\'issabel_user\'\]\);\s*if \(\$postLogin !== \'index\.php\'\) \{.*?\n\s*\}\n)#s', $idx, $m2, PREG_OFFSET_CAPTURE)) { fwrite(STDERR, "Ponto de inserção (sessão ativa) não encontrado no index.php\n"); exit(4); }
        $at = $m2[0][1] + strlen($m2[0][0]);
        $idx = substr_replace($idx, $entrada, $at, 0);
    }
    /* reaplica o bloco de pós-login a cada execução, para levar correções a servidores que já tinham o patch */
    $idx = preg_replace('#\n[ \t]*' . preg_quote($P1, '#') . '.*?' . preg_quote($P2, '#') . '[ \t]*\n#s', "\n", $idx, 1);
    if (strpos($idx, $P1) === false) {
        if (!preg_match('#function get_post_login_redirect\(.*?\n(\s*)return \'index\.php\';\s*\n\}#s', $idx, $m, PREG_OFFSET_CAPTURE)) { fwrite(STDERR, "Ponto de inserção não encontrado no index.php\n"); exit(3); }
        $pos = strrpos(substr($idx, 0, $m[0][1] + strlen($m[0][0])), "    return 'index.php';");
        $idx = substr_replace($idx, $php, $pos, 0);
    }
}
file_put_contents($theme, $tpl); file_put_contents($index, $idx);
echo $undo ? "Escolha de painel removida.\n" : "Escolha de painel aplicada.\n";

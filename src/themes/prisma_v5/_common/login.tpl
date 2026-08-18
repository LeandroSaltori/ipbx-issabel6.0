<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">

	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta name="description" content="Neon Admin Panel" />
	<meta name="author" content="" />

	<title> IPbx - Prisma Telecom - {$PAGE_NAME}</title>
	
	
	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Noto+Sans:400,700,400italic">
	<link rel="stylesheet" href="{$WEBPATH}themes/{$THEMENAME}/css/bootstrap.css">
	<link rel="stylesheet" href="{$WEBPATH}themes/{$THEMENAME}/css/neon-core.css">
	<link rel="stylesheet" href="{$WEBPATH}themes/{$THEMENAME}/css/neon-theme.css">
	<link rel="stylesheet" href="{$WEBPATH}themes/{$THEMENAME}/css/neon-forms.css">
	<link rel="stylesheet" href="{$WEBPATH}themes/{$THEMENAME}/css/custom.css">
	<link rel="stylesheet" href="{$WEBPATH}themes/{$THEMENAME}/css/purple-login.css">

	<!--[if lt IE 9]><script src="{$WEBPATH}themes/{$THEMENAME}/js/ie8-responsive-file-warning.js"></script><![endif]-->

	<!-- HTML5 shim and Respond.js IE8 support of HTML5 elements and media queries -->
	<!--[if lt IE 9]>
		<script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
		<script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
	<![endif]-->

    {$HEADER_LIBS_JQUERY}
</head>
<body class="page-body login-page login-form-fall" data-url="http://neon.dev">


<!-- This is needed when you send requests via Ajax --><script type="text/javascript">
var baseurl = '';
</script>

<div class="login-container">

	<div class="login-header login-caret">

		<div class="login-content">

			<img src="{$WEBPATH}themes/{$THEMENAME}/images/logo_prisma_login.png" width="400" height="124" alt="Issabel logo " />

			<p class="description"></p>

			<!-- progress bar indicator -->
			<div class="login-progressbar-indicator">
				<h3>43%</h3>
				<span>logging in...</span>
			</div>
		</div>

	</div>

	<div class="login-progressbar">
		<div></div>
	</div>

	<div class="login-form">

		<div class="login-content">

			{if $smarty.server.REQUEST_METHOD == 'POST' && $LOGIN_INCORRECT && $LOGIN_INCORRECT != ''}
			<div id="login_error_alert" style="display: block !important; opacity: 1 !important; visibility: visible !important; background: rgba(220, 38, 38, 0.15) !important; border: 1px solid rgba(248, 113, 113, 0.4) !important; color: #fca5a5 !important; border-radius: 8px !important; padding: 12px 16px !important; margin-bottom: 20px !important; text-align: center !important; font-size: 14px !important; font-weight: 500 !important; line-height: 1.4 !important; backdrop-filter: blur(4px) !important; transition: opacity 1s ease-in-out;">
				<i class="entypo-attention" style="margin-right: 6px; font-size: 16px; color: #f87171; vertical-align: middle;"></i>
				<span style="vertical-align: middle;">Usuário ou senha incorretos. Tente novamente.</span>
			</div>
			<script type="text/javascript">
				if (window.history && window.history.replaceState) {
					window.history.replaceState(null, null, window.location.pathname);
				}
				setTimeout(function() {
					var alertBox = document.getElementById('login_error_alert');
					if (alertBox) {
						alertBox.style.opacity = '0';
						setTimeout(function() { alertBox.style.display = 'none'; }, 1000);
					}
				}, 4500);
			</script>
			{/if}

			<form method="post" action="index.php" id="form_login">

				<div class="form-group">

					<div class="input-group">
						<div class="input-group-addon">
							<i class="entypo-user"></i>
						</div>

						<input type="text" class="form-control" name="input_user" id="input_user" placeholder="Usuario" autocomplete="off" />
					</div>

				</div>

				<div class="form-group">

					<div class="input-group">
						<div class="input-group-addon">
							<i class="entypo-key"></i>
						</div>

						<input type="password" class="form-control" name="input_pass" placeholder="Senha" autocomplete="off" />
					</div>

				</div>

				<div class="form-group">
					<button type="submit" class="btn btn-primary btn-block btn-login" name="submit_login">
						<i class="entypo-login"></i>
						Entrar
						<!--{$SUBMIT} REMOVE-->
					</button>
				</div>

			</form>


			<div class="login-bottom-links">

				<a href="http://www.prismatelecom.com" style="text-decoration: none;" target='_blank'>IPbx | Prisma Telecom 2006 - {$currentyear}.</a></div>

			</div>

		</div>

	</div>

</div>


	<!-- Bottom Scripts -->
	<script type='text/javascript' src="{$WEBPATH}themes/{$THEMENAME}/js/gsap/main-gsap.js"></script>
	<script type='text/javascript' src="{$WEBPATH}themes/{$THEMENAME}/js/bootstrap.js"></script>
	<script type='text/javascript' src="{$WEBPATH}themes/{$THEMENAME}/js/joinable.js"></script>
	<script type='text/javascript' src="{$WEBPATH}themes/{$THEMENAME}/js/resizeable.js"></script>
	<script type='text/javascript' src="{$WEBPATH}themes/{$THEMENAME}/js/neon-api.js"></script>
	<script type='text/javascript' src="{$WEBPATH}themes/{$THEMENAME}/js/jquery.validate.min.js"></script>
	<script type='text/javascript' src="{$WEBPATH}themes/{$THEMENAME}/js/neon-login.js"></script>
	<script type='text/javascript' src="{$WEBPATH}themes/{$THEMENAME}/js/neon-custom.js"></script>
	<script type='text/javascript' src="{$WEBPATH}themes/{$THEMENAME}/js/neon-demo.js"></script>

</body>
</html>

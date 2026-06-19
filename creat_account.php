
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="img/icon.png">

    <title>EDVORA</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(rgba(0, 0, 0, 0.18), rgba(0,0,0,0.7)), url('img/main.png') center/cover no-repeat fixed;
            color: #fff;
            display: flex;
            justify-content:left;
            min-height: 100vh;
        }

        .container {
            background: rgba(203, 160, 203, 0.16);
            padding: 48px 40px;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);

        }


        .title {
            font-size: 70px;
            font-weight: 700;
            margin: 0 0 8px;
            text-align: center;
        }

        .subtitle {
            font-size: 18px;
            color: #94a3b8;
            text-align: center;
            margin: 0 0 32px;
        }

        .login-link {
            text-align: center;
            margin-bottom: 32px;
            color: #64748b;
            font-size: 14px;
        }

        .login-link a {
            color: #ffffff;
            text-decoration: none;
            font-weight: 500;
        }

        .form-group {
            display: flex;
            align-items: center;
            gap: 2px;
            margin-bottom: 15px;
        }

        .group-radio {
            display: flex;
            gap: 1px;
            margin: 15px;
            accent-color: #8e77c5;

        }

        .group-radio div {
            display: flex;
            gap:100px;
            margin-left:20px;

        }

        .group-radio div div {
            gap: 1px;
        }

        .input-wrapper {
            flex: 1;
            position: relative;
        }

        input {
            width: 100%;
            padding: 16px 16px 16px 40px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 16px;
            transition: all 0.7s;
        }

        input:focus {
            outline: none;
            border-color: #8e77c5;
            background: rgba(255, 255, 255, 0.08);
        }

        input::placeholder {
            color: #94a3b8;
        }

        .form-group-names {
            gap: 12px;
        }

        .form-group-names input {
            padding: 16px;
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            font-size: 18px;
            user-select: none;
            transition: color 0.3s;
        }

        .toggle-password:hover {
            color: #fff;
        }

        .buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 24px;
        }

        .btn {
            padding: 14px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.5s;
            flex: 1;
            max-width: 200px;
        }

        .btn-create {
            background: #8e77c5;
            color: #fff;
        }

        .btn-create:hover {
            background: #665788;
        }


    </style>
</head>

<body>
<div class="container">

    <h1 class="title">EDVORA</h1>
    <p class="subtitle">Education Academy</p>

    <p class="login-link"> Already have an account? <a href="login.php">Log in</a></p>

    <form action="register.php" method="POST">

        <div class="form-group form-group-names">
            <div class="input-wrapper">
                <input type="text" name="fname" placeholder="First name" pattern="[A-Za-z]+" required>
            </div>
            <div class="input-wrapper">
                <input type="text" name="lname" placeholder="Last name" pattern="[A-Za-z]+" required>
            </div>
        </div>

        <div class="form-group group-radio">
            <label for="birth"><b>Birthdate :</b></label>
            <div class="input-wrapper">
                <input id="birth" name="birth" type="date" required>
            </div>
        </div>

        <div class="group-radio">
            <label><b>Gender :</b></label>
            <div>
                <div>
                    <input id="male" type="radio" name="gender" value="male" required>
                    <label for="male"><b>male</b></label>
                </div>

                <div>
                    <input id="female" type="radio" name="gender" value="female">
                    <label for="female"><b>female</b></label>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="input-wrapper">
                <input type="email" name="email" placeholder="Email" required>
            </div>
        </div>

        <div class="form-group">
            <div class="input-wrapper password-wrapper">
                <input type="password" name="password" placeholder="Password" id="password" minlength="8" required>
                <span class="toggle-password" onclick="togglePassword('password')">👁</span>

            </div>
        </div>

        <div class="form-group">
            <div class="input-wrapper password-wrapper">
                <input type="password" name="conpassword" placeholder="Confirm Password" id="conpassword" minlength="8" required>
                <span class="toggle-password" onclick="togglePassword('conpassword')">👁</span>
            </div>
        </div>
        <?php if(isset($_GET['error'])): ?>
            <div style="background:#ff4d6d; padding:10px; border-radius:8px; margin-bottom:15px; text-align:center;">
                <?= htmlspecialchars($_GET['error']) ?>
            </div>
        <?php endif; ?>
        <div class="buttons">
            <button type="submit" class="btn btn-create">Create account</button>
        </div>

    </form>

</div>

<script>
    function togglePassword(namepass) {
        const passwordInput = document.getElementById(namepass);
        const toggleIcon = document.querySelector('.toggle-password');
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.textContent = '👁'; // Eye closed when visible
        } else {
            passwordInput.type = 'password';
            toggleIcon.textContent = '👁'; // Eye open when hidden
        }
    }

</script>
</body>
</html>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../styles/main.css">
    <title>Employee Registration</title>
</head>


<body>



            <div class="container">

                <h1>Employee Registration</h1>

                <form action="/pages/register.php" method="post">

                    <label for="firstname">First Name</label>
                    <input type="text" name="firstname" id="firstname" placeholder="First Name">

                    <label for="middlename">Middle Name</label>
                    <input type="text" name="middlename" id="middlename" placeholder="Middle Name">

                    <label for="lastname">Last Name</label>
                    <input type="text" name="lastname" id="lastname" placeholder="Last Name">

                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" placeholder="Email">

                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" placeholder="Password">

                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password">

                    <label for="gender">Gender</label>
                    <select name="gender" id="gender">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>

                    <button type="submit">Register</button>
                </form>

            </div>


</body>
</html>
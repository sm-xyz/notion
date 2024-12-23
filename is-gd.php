<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Shortener</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400&display=swap');

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            background-color: #ffffff;
            text-align: center;
            margin: 0;
            padding: 20px;
            max-width: 480px;
            margin: auto;
            box-sizing: border-box;
        }
        input, button {
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
            border: 1px solid #ddd;
            font-size: 1.1rem;
            width: 100%;
            background-color: #fff;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            font-family: 'Montserrat', sans-serif;
            box-sizing: border-box;
        }
        input::placeholder {
            font-size: 1rem;
        }
        button {
            border: none;
            cursor: pointer;
            font-size: 1.1rem;
        }
        #shortenButton {
            background-color: #007bff;
            color: white;
            font-weight: bold;
        }
        #result {
            margin: 20px 0;
            word-wrap: break-word;
            color: #007bff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            body {
                color: #fff;
                background-color: #333;
            }
            input, button {
                border: 1px solid #555;
                background-color: #444;
            }
        }
    </style>
</head>
<body>
    <h1>Link Shortener</h1>
    <p>Masukkan URL yang ingin kamu pendekkan:</p>
    <form method="POST" action="">
        <input type="text" name="longUrl" placeholder="Masukkan URL panjang">
        <button type="submit" name="shortenButton" id="shortenButton">Shorten Link</button>
    </form>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['longUrl'])) {
        $longUrl = $_POST['longUrl'];
        $apiUrl = 'https://is.gd/create.php?format=json&url=' . urlencode($longUrl);
        
        $response = file_get_contents($apiUrl);
        $data = json_decode($response);

        if (isset($data->shorturl)) {
            echo "<p id='result'><a href='{$data->shorturl}' target='_blank'>{$data->shorturl}</a></p>";
            echo "<button id='copyButton' onclick='copyLink()'>Copy</button>";
        } else {
            echo "<p id='result'>Error: Tidak dapat memendekkan URL.</p>";
        }
    }
    ?>

    <script>
        function copyLink() {
            const copyText = document.getElementById('result').innerText;
            navigator.clipboard.writeText(copyText).then(() => {
                alert('Link berhasil disalin!');
            }, (err) => {
                console.error('Failed to copy text: ', err);
            });
        }
    </script>
</body>
</html>

$ErrorActionPreference = 'Stop'
$configPath = 'C:\wamp64\private\sps-notifications.ini'
if (-not (Test-Path -LiteralPath $configPath -PathType Leaf)) {
    throw 'The local notification settings file was not found.'
}

$securePassword = Read-Host 'Enter the NEW Google App Password (it will not be echoed)' -AsSecureString
$bstr = [IntPtr]::Zero
try {
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    $plainPassword = $plainPassword -replace '\s', ''
    if ([string]::IsNullOrWhiteSpace($plainPassword)) {
        throw 'No password was entered; local settings were not changed.'
    }

    $escapedPassword = $plainPassword.Replace('\', '\\').Replace('"', '\"')
    $contents = [System.IO.File]::ReadAllText($configPath)
    $updated = [regex]::Replace($contents, '(?m)^SPS_SMTP_PASSWORD\s*=.*$', ('SPS_SMTP_PASSWORD = "' + $escapedPassword + '"'))
    if ($updated -eq $contents) {
        throw 'The password setting was not found; local settings were not changed.'
    }

    [System.IO.File]::WriteAllText($configPath, $updated, (New-Object System.Text.UTF8Encoding($false)))
    Write-Output 'Saved the new SMTP App Password to the protected local settings file.'
}
finally {
    if ($bstr -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
    if ($null -ne $plainPassword) {
        $plainPassword = $null
    }
    if ($null -ne $securePassword) {
        $securePassword.Dispose()
    }
}

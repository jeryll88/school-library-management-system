$ErrorActionPreference = 'Stop'

function ConvertTo-PlainText([System.Security.SecureString]$Value) {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    }
    finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

$password = Read-Host 'New Admin password (at least 12 characters)' -AsSecureString
$confirmation = Read-Host 'Confirm new Admin password' -AsSecureString
if ($password.Length -lt 12) {
    throw 'The password must contain at least 12 characters.'
}

$plainPassword = ConvertTo-PlainText $password
$plainConfirmation = ConvertTo-PlainText $confirmation
if ($plainPassword -cne $plainConfirmation) {
    $plainPassword = $null
    $plainConfirmation = $null
    throw 'The passwords do not match. No changes were made.'
}

$php = 'C:\xampp\php\php.exe'
$worker = Join-Path $PSScriptRoot 'reset_admin_password.php'
if (-not (Test-Path -LiteralPath $php)) {
    throw "PHP CLI was not found at $php."
}

$startInfo = New-Object System.Diagnostics.ProcessStartInfo
$startInfo.FileName = $php
$startInfo.Arguments = '"' + $worker + '"'
$startInfo.WorkingDirectory = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$startInfo.UseShellExecute = $false
$startInfo.CreateNoWindow = $true
$startInfo.RedirectStandardInput = $true
$startInfo.RedirectStandardOutput = $true
$startInfo.RedirectStandardError = $true

$process = New-Object System.Diagnostics.Process
$process.StartInfo = $startInfo
try {
    if (-not $process.Start()) {
        throw 'Could not start PHP CLI.'
    }
    $process.StandardInput.WriteLine($plainPassword)
    $process.StandardInput.WriteLine($plainConfirmation)
    $process.StandardInput.Close()
    $output = $process.StandardOutput.ReadToEnd()
    $errorOutput = $process.StandardError.ReadToEnd()
    $process.WaitForExit()

    if ($process.ExitCode -ne 0) {
        if ($errorOutput) {
            [Console]::Error.WriteLine($errorOutput.Trim())
        }
        if ($output) {
            [Console]::Error.WriteLine($output.Trim())
        }
        throw 'The Admin password was not reset.'
    }
    [Console]::WriteLine($output.Trim())
}
finally {
    $plainPassword = $null
    $plainConfirmation = $null
    $password.Dispose()
    $confirmation.Dispose()
    $process.Dispose()
}

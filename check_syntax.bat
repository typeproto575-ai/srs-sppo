@echo off
setlocal enabledelayedexpansion
> syntax_results.txt
for /r %%f in (*.php) do (
  C:\xampp\php\php.exe -l "%%f" > tmp_lint.txt 2>&1
  findstr /v "No syntax errors" tmp_lint.txt > nul
  if !errorlevel! equ 0 (
    echo BAD: %%f >> syntax_results.txt
    type tmp_lint.txt >> syntax_results.txt
  )
)
del tmp_lint.txt 2>nul
echo DONE


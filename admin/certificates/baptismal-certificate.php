<?php
/**
 * Baptismal certificate renderer (St. Patrick Parish blank stock).
 *
 * Draws the certificate as one SVG so every label, rule and value sits at a
 * measured baseline and prints at true physical size. Positions live in
 * print-coordinates.php; this file only turns them into markup.
 *
 *   baptismal_certificate_svg($layout, $values, $opts)
 *       -> SVG markup. Template artwork is wrapped in <g class="bc-template">
 *          so print CSS can hide it when printing onto pre-printed stock.
 *   baptismal_certificate_values($source, $req, $sponsorNames)
 *       -> maps a record/request row to the field keys used by the layout.
 *   baptismal_certificate_fit_script()
 *       -> tiny script that shrinks over-long values to fit their line.
 */

function bc_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bc_n(float $v): string
{
    return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
}

/** Vector logo traced from the parish stock: ring + trefoil/cross mark, centred on 0,0 with ring diameter 1. */
function baptismal_logo_paths(): array
{
    return [
        'ring' => 'M-0.5 0a0.5 0.5 0 1 0 1 0a0.5 0.5 0 1 0 -1 0ZM-0.4639 0a0.4639 0.4639 0 1 0 0.9278 0a0.4639 0.4639 0 1 0 -0.9278 0Z',
        'mark' => 'M0.3450 -0.1561C0.3396 -0.1670 0.3294 -0.1813 0.3195 -0.1919C0.3095 -0.2026 0.2962 -0.2135 0.2854 -0.2200C0.2746 -0.2264 0.2665 -0.2282 0.2546 -0.2305C0.2427 -0.2328 0.2219 -0.2327 0.2139 -0.2338C0.2058 -0.2349 0.2080 -0.2354 0.2062 -0.2372C0.2044 -0.2389 0.2040 -0.2368 0.2031 -0.2443C0.2023 -0.2518 0.2033 -0.2701 0.2009 -0.2821C0.1984 -0.2942 0.1953 -0.3052 0.1886 -0.3165C0.1818 -0.3279 0.1703 -0.3410 0.1603 -0.3502C0.1503 -0.3593 0.1397 -0.3662 0.1285 -0.3715C0.1174 -0.3768 0.1046 -0.3803 0.0935 -0.3820C0.0824 -0.3838 0.0700 -0.3827 0.0620 -0.3821C0.0540 -0.3815 0.0561 -0.3820 0.0455 -0.3783C0.0349 -0.3747 0.0128 -0.3604 -0.0018 -0.3601C-0.0163 -0.3598 -0.0323 -0.3732 -0.0419 -0.3768C-0.0514 -0.3803 -0.0511 -0.3804 -0.0591 -0.3814C-0.0671 -0.3823 -0.0814 -0.3827 -0.0899 -0.3822C-0.0983 -0.3817 -0.1023 -0.3805 -0.1099 -0.3782C-0.1175 -0.3760 -0.1283 -0.3721 -0.1357 -0.3686C-0.1431 -0.3650 -0.1488 -0.3611 -0.1542 -0.3570C-0.1597 -0.3529 -0.1632 -0.3499 -0.1684 -0.3440C-0.1737 -0.3381 -0.1814 -0.3277 -0.1857 -0.3215C-0.1899 -0.3154 -0.1913 -0.3133 -0.1939 -0.3072C-0.1965 -0.3011 -0.1997 -0.2956 -0.2013 -0.2850C-0.2029 -0.2744 -0.2027 -0.2518 -0.2036 -0.2437C-0.2045 -0.2356 -0.2050 -0.2381 -0.2068 -0.2364C-0.2086 -0.2348 -0.2070 -0.2346 -0.2146 -0.2337C-0.2222 -0.2329 -0.2406 -0.2339 -0.2524 -0.2316C-0.2642 -0.2292 -0.2764 -0.2238 -0.2853 -0.2195C-0.2943 -0.2153 -0.3002 -0.2104 -0.3059 -0.2061C-0.3117 -0.2017 -0.3137 -0.2009 -0.3198 -0.1935C-0.3260 -0.1862 -0.3373 -0.1726 -0.3427 -0.1618C-0.3482 -0.1511 -0.3511 -0.1405 -0.3528 -0.1289C-0.3544 -0.1173 -0.3539 -0.1030 -0.3527 -0.0924C-0.3515 -0.0818 -0.3491 -0.0740 -0.3457 -0.0652C-0.3422 -0.0563 -0.3344 -0.0450 -0.3319 -0.0394C-0.3293 -0.0338 -0.3280 -0.0378 -0.3302 -0.0315C-0.3323 -0.0252 -0.3409 -0.0109 -0.3445 -0.0014C-0.3481 0.0080 -0.3503 0.0147 -0.3516 0.0250C-0.3529 0.0354 -0.3530 0.0517 -0.3525 0.0609C-0.3519 0.0700 -0.3522 0.0710 -0.3485 0.0802C-0.3449 0.0894 -0.3360 0.1072 -0.3305 0.1160C-0.3249 0.1247 -0.3208 0.1275 -0.3153 0.1327C-0.3098 0.1378 -0.3041 0.1426 -0.2975 0.1469C-0.2910 0.1512 -0.2833 0.1555 -0.2760 0.1585C-0.2688 0.1616 -0.2604 0.1634 -0.2538 0.1650C-0.2473 0.1666 -0.2437 0.1675 -0.2366 0.1680C-0.2296 0.1685 -0.2213 0.1697 -0.2116 0.1681C-0.2019 0.1664 -0.1890 0.1623 -0.1786 0.1580C-0.1683 0.1536 -0.1585 0.1482 -0.1496 0.1419C-0.1408 0.1356 -0.1404 0.1356 -0.1254 0.1199C-0.1104 0.1043 -0.0713 0.0620 -0.0597 0.0480C-0.0481 0.0339 -0.0561 0.0398 -0.0559 0.0358C-0.0558 0.0318 -0.0569 0.0266 -0.0587 0.0237C-0.0606 0.0208 -0.0639 0.0189 -0.0671 0.0184C-0.0702 0.0178 -0.0599 0.0043 -0.0777 0.0204C-0.0955 0.0364 -0.1533 0.0963 -0.1738 0.1145C-0.1943 0.1328 -0.1931 0.1267 -0.2008 0.1300C-0.2086 0.1333 -0.2117 0.1343 -0.2202 0.1345C-0.2287 0.1347 -0.2411 0.1347 -0.2517 0.1313C-0.2622 0.1278 -0.2762 0.1187 -0.2834 0.1139C-0.2906 0.1090 -0.2906 0.1075 -0.2948 0.1022C-0.2990 0.0970 -0.3049 0.0901 -0.3084 0.0823C-0.3119 0.0746 -0.3147 0.0640 -0.3158 0.0558C-0.3170 0.0477 -0.3162 0.0404 -0.3155 0.0336C-0.3147 0.0268 -0.3131 0.0209 -0.3112 0.0150C-0.3092 0.0092 -0.3079 0.0046 -0.3039 -0.0016C-0.2998 -0.0078 -0.2901 -0.0172 -0.2868 -0.0222C-0.2835 -0.0272 -0.2840 -0.0283 -0.2840 -0.0315C-0.2840 -0.0347 -0.2837 -0.0358 -0.2870 -0.0415C-0.2903 -0.0473 -0.2995 -0.0586 -0.3040 -0.0659C-0.3085 -0.0732 -0.3119 -0.0797 -0.3139 -0.0852C-0.3159 -0.0907 -0.3158 -0.0921 -0.3160 -0.0988C-0.3162 -0.1055 -0.3160 -0.1182 -0.3151 -0.1253C-0.3142 -0.1325 -0.3128 -0.1363 -0.3108 -0.1418C-0.3087 -0.1473 -0.3087 -0.1511 -0.3028 -0.1581C-0.2969 -0.1651 -0.2832 -0.1779 -0.2753 -0.1838C-0.2674 -0.1897 -0.2610 -0.1913 -0.2553 -0.1935C-0.2495 -0.1957 -0.2467 -0.1964 -0.2409 -0.1971C-0.2352 -0.1979 -0.2273 -0.1984 -0.2209 -0.1979C-0.2145 -0.1974 -0.2108 -0.1981 -0.2023 -0.1942C-0.1937 -0.1902 -0.1895 -0.1907 -0.1697 -0.1742C-0.1499 -0.1578 -0.1000 -0.1092 -0.0834 -0.0955C-0.0668 -0.0818 -0.0732 -0.0921 -0.0699 -0.0919C-0.0665 -0.0917 -0.0651 -0.0931 -0.0633 -0.0943C-0.0614 -0.0956 -0.0596 -0.0967 -0.0586 -0.0994C-0.0576 -0.1020 -0.0567 -0.1067 -0.0573 -0.1103C-0.0578 -0.1138 -0.0477 -0.1056 -0.0620 -0.1206C-0.0763 -0.1356 -0.1269 -0.1829 -0.1431 -0.2000C-0.1592 -0.2172 -0.1551 -0.2161 -0.1588 -0.2234C-0.1626 -0.2308 -0.1645 -0.2352 -0.1656 -0.2442C-0.1666 -0.2531 -0.1660 -0.2693 -0.1652 -0.2771C-0.1644 -0.2850 -0.1627 -0.2868 -0.1607 -0.2915C-0.1586 -0.2961 -0.1576 -0.2993 -0.1530 -0.3050C-0.1485 -0.3108 -0.1425 -0.3195 -0.1335 -0.3259C-0.1245 -0.3324 -0.1078 -0.3405 -0.0992 -0.3440C-0.0906 -0.3474 -0.0889 -0.3464 -0.0820 -0.3465C-0.0751 -0.3467 -0.0656 -0.3469 -0.0576 -0.3447C-0.0496 -0.3425 -0.0417 -0.3379 -0.0340 -0.3335C-0.0264 -0.3292 -0.0173 -0.3217 -0.0117 -0.3187C-0.0061 -0.3158 -0.0040 -0.3159 -0.0003 -0.3159C0.0033 -0.3159 0.0013 -0.3141 0.0103 -0.3187C0.0192 -0.3233 0.0393 -0.3393 0.0534 -0.3437C0.0675 -0.3481 0.0859 -0.3454 0.0949 -0.3450C0.1038 -0.3446 0.1017 -0.3439 0.1071 -0.3415C0.1124 -0.3391 0.1196 -0.3360 0.1269 -0.3305C0.1343 -0.3250 0.1457 -0.3152 0.1514 -0.3083C0.1571 -0.3014 0.1587 -0.2947 0.1610 -0.2893C0.1632 -0.2839 0.1641 -0.2819 0.1649 -0.2757C0.1657 -0.2695 0.1663 -0.2594 0.1658 -0.2521C0.1653 -0.2448 0.1658 -0.2407 0.1619 -0.2320C0.1579 -0.2233 0.1534 -0.2131 0.1421 -0.1999C0.1308 -0.1867 0.1076 -0.1669 0.0940 -0.1529C0.0804 -0.1389 0.0666 -0.1234 0.0605 -0.1160C0.0543 -0.1087 0.0578 -0.1112 0.0573 -0.1089C0.0568 -0.1065 0.0569 -0.1041 0.0576 -0.1017C0.0582 -0.0993 0.0596 -0.0964 0.0611 -0.0944C0.0626 -0.0925 0.0641 -0.0908 0.0666 -0.0899C0.0692 -0.0889 0.0731 -0.0883 0.0762 -0.0887C0.0793 -0.0891 0.0741 -0.0820 0.0851 -0.0922C0.0961 -0.1024 0.1276 -0.1357 0.1422 -0.1499C0.1568 -0.1641 0.1644 -0.1706 0.1727 -0.1772C0.1809 -0.1838 0.1857 -0.1863 0.1916 -0.1894C0.1975 -0.1926 0.1999 -0.1949 0.2080 -0.1962C0.2161 -0.1974 0.2308 -0.1982 0.2403 -0.1971C0.2497 -0.1959 0.2575 -0.1924 0.2646 -0.1893C0.2717 -0.1861 0.2759 -0.1842 0.2827 -0.1782C0.2896 -0.1721 0.3002 -0.1628 0.3057 -0.1531C0.3112 -0.1435 0.3144 -0.1313 0.3158 -0.1203C0.3171 -0.1094 0.3153 -0.0957 0.3138 -0.0874C0.3123 -0.0790 0.3115 -0.0780 0.3068 -0.0702C0.3022 -0.0624 0.2899 -0.0475 0.2860 -0.0407C0.2821 -0.0339 0.2833 -0.0328 0.2834 -0.0294C0.2835 -0.0260 0.2831 -0.0251 0.2865 -0.0203C0.2898 -0.0154 0.2996 -0.0055 0.3034 -0.0003C0.3073 0.0050 0.3074 0.0062 0.3095 0.0115C0.3115 0.0167 0.3146 0.0248 0.3156 0.0315C0.3167 0.0382 0.3162 0.0449 0.3158 0.0515C0.3154 0.0582 0.3146 0.0660 0.3131 0.0716C0.3117 0.0772 0.3103 0.0800 0.3073 0.0852C0.3042 0.0904 0.2996 0.0973 0.2949 0.1028C0.2903 0.1083 0.2849 0.1140 0.2795 0.1181C0.2741 0.1223 0.2685 0.1252 0.2625 0.1278C0.2564 0.1305 0.2508 0.1330 0.2431 0.1340C0.2355 0.1351 0.2245 0.1351 0.2166 0.1342C0.2088 0.1333 0.2036 0.1324 0.1959 0.1288C0.1881 0.1251 0.1896 0.1303 0.1700 0.1125C0.1504 0.0946 0.0947 0.0373 0.0782 0.0217C0.0617 0.0060 0.0735 0.0191 0.0712 0.0186C0.0690 0.0182 0.0671 0.0183 0.0649 0.0191C0.0627 0.0199 0.0598 0.0216 0.0580 0.0233C0.0561 0.0251 0.0546 0.0270 0.0538 0.0295C0.0530 0.0321 0.0524 0.0355 0.0532 0.0386C0.0540 0.0418 0.0452 0.0335 0.0585 0.0485C0.0719 0.0635 0.1175 0.1123 0.1333 0.1286C0.1492 0.1448 0.1462 0.1412 0.1536 0.1461C0.1611 0.1511 0.1683 0.1546 0.1780 0.1583C0.1876 0.1621 0.2015 0.1668 0.2116 0.1686C0.2218 0.1704 0.2309 0.1698 0.2388 0.1693C0.2467 0.1688 0.2514 0.1677 0.2589 0.1655C0.2664 0.1634 0.2764 0.1595 0.2839 0.1561C0.2914 0.1528 0.2974 0.1499 0.3039 0.1453C0.3104 0.1406 0.3178 0.1337 0.3230 0.1282C0.3282 0.1226 0.3305 0.1201 0.3350 0.1117C0.3396 0.1032 0.3472 0.0881 0.3502 0.0773C0.3532 0.0666 0.3532 0.0570 0.3532 0.0472C0.3531 0.0375 0.3538 0.0314 0.3500 0.0186C0.3462 0.0058 0.3300 -0.0118 0.3302 -0.0294C0.3305 -0.0469 0.3479 -0.0704 0.3515 -0.0867C0.3552 -0.1029 0.3532 -0.1152 0.3521 -0.1268C0.3510 -0.1383 0.3505 -0.1453 0.3450 -0.1561ZM-0.0011 -0.2295C-0.0050 -0.2288 -0.0128 -0.2265 -0.0164 -0.2232C-0.0200 -0.2199 -0.0212 -0.2287 -0.0225 -0.2098C-0.0237 -0.1909 -0.0240 -0.1336 -0.0239 -0.1096C-0.0238 -0.0856 -0.0218 -0.0749 -0.0220 -0.0659C-0.0222 -0.0570 -0.0231 -0.0581 -0.0251 -0.0560C-0.0271 -0.0539 -0.0090 -0.0533 -0.0341 -0.0531C-0.0592 -0.0530 -0.1494 -0.0557 -0.1758 -0.0549C-0.2022 -0.0540 -0.1887 -0.0504 -0.1924 -0.0481C-0.1960 -0.0459 -0.1965 -0.0437 -0.1976 -0.0414C-0.1988 -0.0391 -0.1999 -0.0382 -0.1992 -0.0344C-0.1985 -0.0305 -0.1965 -0.0222 -0.1935 -0.0184C-0.1904 -0.0145 -0.1988 -0.0127 -0.1808 -0.0113C-0.1628 -0.0098 -0.1097 -0.0097 -0.0856 -0.0097C-0.0615 -0.0097 -0.0459 -0.0112 -0.0362 -0.0112C-0.0264 -0.0113 -0.0296 -0.0107 -0.0273 -0.0099C-0.0251 -0.0091 -0.0234 -0.0593 -0.0227 -0.0064C-0.0220 0.0464 -0.0233 0.2530 -0.0230 0.3072C-0.0227 0.3614 -0.0221 0.3153 -0.0209 0.3186C-0.0198 0.3220 -0.0183 0.3248 -0.0161 0.3273C-0.0140 0.3297 -0.0110 0.3319 -0.0081 0.3333C-0.0052 0.3347 -0.0017 0.3354 0.0011 0.3354C0.0039 0.3354 0.0064 0.3346 0.0089 0.3335C0.0113 0.3323 0.0138 0.3317 0.0159 0.3284C0.0180 0.3251 0.0206 0.3694 0.0216 0.3136C0.0225 0.2578 0.0210 0.0474 0.0216 -0.0066C0.0223 -0.0605 0.0235 -0.0091 0.0255 -0.0099C0.0274 -0.0106 0.0082 -0.0113 0.0333 -0.0111C0.0585 -0.0110 0.1501 -0.0079 0.1765 -0.0088C0.2029 -0.0097 0.1881 -0.0127 0.1917 -0.0165C0.1952 -0.0203 0.1971 -0.0277 0.1978 -0.0315C0.1986 -0.0353 0.1972 -0.0368 0.1961 -0.0393C0.1949 -0.0417 0.1945 -0.0438 0.1909 -0.0461C0.1873 -0.0485 0.2006 -0.0522 0.1744 -0.0534C0.1481 -0.0546 0.0583 -0.0528 0.0334 -0.0532C0.0085 -0.0536 0.0268 -0.0539 0.0248 -0.0560C0.0227 -0.0581 0.0216 -0.0406 0.0211 -0.0659C0.0206 -0.0912 0.0227 -0.1815 0.0217 -0.2077C0.0207 -0.2338 0.0174 -0.2194 0.0150 -0.2228C0.0127 -0.2261 0.0101 -0.2266 0.0075 -0.2277C0.0048 -0.2288 0.0029 -0.2302 -0.0011 -0.2295ZM0.0495 0.2548C0.0477 0.2580 0.0468 0.2633 0.0475 0.2670C0.0481 0.2707 0.0511 0.2749 0.0535 0.2773C0.0559 0.2797 0.0550 0.2794 0.0620 0.2815C0.0690 0.2836 0.0835 0.2827 0.0956 0.2900C0.1077 0.2972 0.1249 0.3172 0.1346 0.3250C0.1443 0.3328 0.1477 0.3336 0.1536 0.3368C0.1595 0.3400 0.1644 0.3423 0.1701 0.3441C0.1758 0.3460 0.1831 0.3474 0.1880 0.3478C0.1928 0.3481 0.1961 0.3474 0.1992 0.3460C0.2023 0.3447 0.2049 0.3425 0.2064 0.3396C0.2078 0.3367 0.2084 0.3322 0.2077 0.3288C0.2070 0.3253 0.2095 0.3233 0.2022 0.3189C0.1948 0.3146 0.1754 0.3103 0.1636 0.3025C0.1519 0.2947 0.1394 0.2789 0.1316 0.2722C0.1238 0.2655 0.1232 0.2658 0.1171 0.2624C0.1110 0.2590 0.1024 0.2542 0.0949 0.2517C0.0874 0.2491 0.0781 0.2478 0.0720 0.2472C0.0658 0.2466 0.0616 0.2468 0.0579 0.2481C0.0541 0.2494 0.0512 0.2517 0.0495 0.2548ZM-0.0504 0.2552C-0.0521 0.2521 -0.0544 0.2494 -0.0586 0.2479C-0.0628 0.2465 -0.0700 0.2463 -0.0755 0.2465C-0.0811 0.2468 -0.0856 0.2475 -0.0920 0.2495C-0.0984 0.2515 -0.1072 0.2548 -0.1142 0.2585C-0.1212 0.2623 -0.1259 0.2648 -0.1342 0.2718C-0.1424 0.2788 -0.1522 0.2931 -0.1636 0.3005C-0.1750 0.3079 -0.1953 0.3119 -0.2026 0.3162C-0.2099 0.3204 -0.2072 0.3223 -0.2077 0.3259C-0.2081 0.3294 -0.2070 0.3346 -0.2055 0.3376C-0.2041 0.3406 -0.2019 0.3425 -0.1990 0.3439C-0.1961 0.3453 -0.1924 0.3460 -0.1880 0.3460C-0.1835 0.3460 -0.1773 0.3451 -0.1722 0.3436C-0.1671 0.3421 -0.1629 0.3402 -0.1572 0.3371C-0.1514 0.3341 -0.1487 0.3333 -0.1379 0.3252C-0.1270 0.3171 -0.1058 0.2966 -0.0920 0.2887C-0.0782 0.2807 -0.0621 0.2811 -0.0548 0.2775C-0.0476 0.2739 -0.0492 0.2708 -0.0485 0.2670C-0.0477 0.2633 -0.0487 0.2584 -0.0504 0.2552Z',
    ];
}

function baptismal_certificate_svg(array $layout, array $values, array $opts = []): string
{
    $wIn = (float) $layout['page']['width_in'];
    $hIn = (float) $layout['page']['height_in'];
    $hu  = 100 * $hIn / $wIn;          // viewBox height (width is 100 units)
    $yk  = $hu / 100;                  // % of page height -> viewBox units
    $col = $layout['colors'];
    $fonts = $layout['fonts'];
    $tpl = $layout['template'];
    $logo = baptismal_logo_paths();
    $c = static fn(string $k): string => $col[$k] ?? '#000';

    $o = [];
    $o[] = '<svg class="bc-svg" viewBox="0 0 100 ' . bc_n($hu) . '" width="' . bc_n($wIn) . 'in" height="' . bc_n($hIn)
        . 'in" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Baptismal certificate">';

    // ---- pre-printed artwork (hidden when printing onto the real stock) ----
    $o[] = '<g class="bc-template">';
    $o[] = '<rect x="0" y="0" width="100" height="' . bc_n($hu) . '" fill="#fff"/>';
    $wm = $tpl['watermark'];
    $o[] = '<g transform="translate(' . bc_n($wm['cx']) . ' ' . bc_n($wm['cy'] * $yk) . ') scale(' . bc_n($wm['d']) . ')"><path d="' . $logo['mark'] . '" fill="' . $c('watermark') . '"/></g>';
    $lg = $tpl['logo'];
    $o[] = '<g transform="translate(' . bc_n($lg['cx']) . ' ' . bc_n($lg['cy'] * $yk) . ') scale(' . bc_n($lg['d']) . ')" fill="' . $c('green') . '">'
        . '<path d="' . $logo['ring'] . '" fill-rule="evenodd"/><path d="' . $logo['mark'] . '"/></g>';

    $rule = static function (array $r) use ($yk, $c): string {
        return '<rect x="' . bc_n($r['x']) . '" y="' . bc_n($r['y'] * $yk - $r['th'] / 2) . '" width="' . bc_n($r['w'])
            . '" height="' . bc_n($r['th']) . '" fill="' . $c($r['color']) . '"/>';
    };
    foreach ($tpl['header_rules'] as $r) $o[] = $rule($r);
    foreach ($tpl['rules'] as $r) $o[] = $rule($r);

    foreach ($tpl['texts'] as $t) {
        $y = $t['base'] * $yk;
        $o[] = '<g font-family="' . bc_h($fonts[$t['family']]) . '" font-weight="' . (int) $t['weight'] . '" fill="' . $c($t['color']) . '"'
            . (!empty($t['stroke']) ? ' stroke="' . $c($t['color']) . '" stroke-width="' . bc_n($t['stroke']) . '" stroke-linejoin="round"' : '') . '>';
        foreach ($t['words'] as [$x, $len, $content]) {
            $inner = '';
            if (is_array($content)) {
                foreach ($content as [$chars, $scale]) {
                    $inner .= '<tspan font-size="' . bc_n($t['size'] * $scale) . '">' . bc_h($chars) . '</tspan>';
                }
            } else {
                $inner = bc_h($content);
            }
            $o[] = '<text x="' . bc_n($x) . '" y="' . bc_n($y) . '" font-size="' . bc_n($t['size']) . '" textLength="' . bc_n($len)
                . '" lengthAdjust="spacing">' . $inner . '</text>';
        }
        $o[] = '</g>';
    }
    $o[] = '</g>';

    // ---- values printed by the system ----
    $o[] = '<g class="bc-values" font-family="' . bc_h($fonts['serif']) . '" fill="' . $c('value') . '">';
    foreach ($layout['fields'] as $key => $f) {
        $v = trim((string) ($values[$key] ?? ''));
        if ($v === '') continue;
        $anchor = ($f['align'] ?? 'start') === 'middle' ? 'middle' : 'start';
        $family = $fonts[$f['family'] ?? 'serif'] ?? $fonts['serif'];
        $weight = (int) ($f['weight'] ?? 400);
        $o[] = '<text data-field="' . bc_h($key) . '" data-max="' . bc_n($f['width_pct']) . '" x="' . bc_n($f['x_pct']) . '" y="' . bc_n($f['baseline_pct'] * $yk)
            . '" font-family="' . bc_h($family) . '" font-weight="' . $weight . '" font-size="' . bc_n($f['size_pct']) . '" text-anchor="' . $anchor . '">' . bc_h($v) . '</text>';
    }
    $o[] = '</g></svg>';
    return implode("\n", $o);
}

/** Shrinks any value wider than its line (font first, then glyph squeeze). Runs on load and before printing. */
function baptismal_certificate_fit_script(): string
{
    return <<<'JS'
<script>
(function () {
    function fitAll() {
        document.querySelectorAll('.bc-values text[data-max]').forEach(function (t) {
            var max = parseFloat(t.getAttribute('data-max'));
            var base = parseFloat(t.getAttribute('data-size') || t.getAttribute('font-size'));
            t.setAttribute('data-size', base);
            t.setAttribute('font-size', base);
            t.removeAttribute('textLength');
            t.removeAttribute('lengthAdjust');
            var len = t.getComputedTextLength();
            if (len > max) {
                t.setAttribute('font-size', (base * Math.max(max / len, 0.72)).toFixed(3));
                len = t.getComputedTextLength();
                if (len > max) {
                    t.setAttribute('textLength', max.toFixed(3));
                    t.setAttribute('lengthAdjust', 'spacingAndGlyphs');
                }
            }
        });
    }
    function run() { try { fitAll(); } catch (e) {} }
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(run);
    document.addEventListener('DOMContentLoaded', run);
    window.addEventListener('load', run);
    window.addEventListener('beforeprint', run);
    /* mode: 'overlay' = values only (pre-printed stock), 'full' = whole certificate (plain paper) */
    window.bcPrint = function (mode) {
        document.body.setAttribute('data-cert-print', mode);
        run();
        window.print();
    };
    window.addEventListener('afterprint', function () { document.body.removeAttribute('data-cert-print'); });
})();
</script>
JS;
}

/**
 * Map a register record (or, failing that, the requester's submitted details)
 * plus the request row to the layout's field keys.
 *
 * @param array      $source        matched record row, or the extension row
 * @param array      $req           certificate_requests row
 * @param string[]   $sponsorNames  sponsor names in order (max 8 are printed)
 */
function baptismal_certificate_values(array $source, array $req, array $sponsorNames = []): array
{
    $first = static function (array $row, array $keys): string {
        foreach ($keys as $k) {
            if (isset($row[$k]) && trim((string) $row[$k]) !== '') return trim((string) $row[$k]);
        }
        return '';
    };
    $part = static function (string $raw, string $fmt): string {
        $ts = $raw !== '' ? strtotime($raw) : false;
        return $ts ? date($fmt, $ts) : '';
    };
    $firstAndLast = static function (string $raw): string {
        $parts = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 2) return trim($raw);
        return $parts[0] . ' ' . end($parts);
    };

    $birth = $first($source, ['birth_date']);
    $bapt  = $first($source, ['date_of_baptism', 'baptism_date']);

    $purpose = ($req['purpose'] ?? '') === 'unspecified'
        ? ''
        : ((($req['purpose'] ?? '') === 'other' && !empty($req['purpose_other']))
            ? (string) $req['purpose_other']
            : ucwords(str_replace('_', ' ', (string) ($req['purpose'] ?? ''))));

    $v = [
        'subject_full_name'  => cert_format_upper_case($first($source, ['child_full_name'])),
        'father_name'        => cert_format_title_case($firstAndLast($first($source, ['father_name', 'father_full_name']))),
        'mother_name'        => cert_format_title_case($firstAndLast($first($source, ['mother_maiden_name', 'mother_name', 'mother_full_name']))),
        'place_of_birth'     => $first($source, ['child_birthplace', 'birthplace', 'birth_place', 'place_of_birth']),
        'birth_date_day'     => $part($birth, 'jS'),
        'birth_date_month'   => $part($birth, 'F'),
        'birth_date_year'    => $part($birth, 'Y'),
        'baptism_date_day'   => $part($bapt, 'jS'),
        'baptism_date_month' => $part($bapt, 'F'),
        'baptism_date_year'  => $part($bapt, 'Y'),
        'officiating_priest' => cert_format_title_case($first($source, ['minister_name', 'officiating_minister'])),
        'register_book'      => $first($source, ['book_no']),
        'register_page'      => $first($source, ['page_no']),
        'register_line'      => $first($source, ['line_no']),
        'purpose'            => trim($purpose),
        'date_issued'        => date('F j, Y'),
        // 'remarks' is left blank on purpose: register remarks can hold internal notes.
    ];
    // Sponsors fill row by row: left, right, left, right ...
    foreach (array_values($sponsorNames) as $i => $name) {
        if ($i >= 8) break;
        $v['sponsor_' . ($i + 1)] = cert_format_title_case(trim((string) $name));
    }
    return $v;
}

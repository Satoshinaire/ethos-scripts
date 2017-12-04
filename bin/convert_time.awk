#!/usr/bin/awk -f  
BEGIN { FS = ":" }
{
  if (NF == 2) {
    print $1*60 + $2
  } else if (NF == 3) {
    split($1, a, "-");
    if (a[2] != "" ) {
      print ((a[1]*24+a[2])*60 + $2) * 60 + $3;
    } else {
      print ($1*60 + $2) * 60 + $3;
    }
  }
}

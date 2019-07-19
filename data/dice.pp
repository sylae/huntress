%skip   space     [\x20\x09]+
%token  bracket_  \(
%token _bracket   \)
%token  comma     ,
%token  number    (0|[1-9]\d*)(\.\d+)?([eE][\+\-]?\d+)?
%token  plus      \+
%token  minus     \-|−
%token  times     \*|×
%token  div       /|÷
%token  dice      d
%token  power     \^
%token  id        \w+

expression:
    primary() ( ::plus:: #addition expression() )?

primary:
    secondary() ( ::minus:: #substraction expression() )?

secondary:
    ternary() ( ::times:: #multiplication expression() )?

ternary:
    quat() ( ::div:: #division expression() )?

quat:
    quin() ( ::power:: #exp expression() )?

quin:
    term() ( ::dice:: #roll expression() )?

term:
    ( ::bracket_:: expression() ::_bracket:: #group )
  | number()
  | ( ::minus:: #negative | ::plus:: ) term()

number:
    <number>

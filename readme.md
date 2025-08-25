# Declarative

Forth-like, declarative external DSL, run in PHP and JavaScript.

Agnostic about client/server. Meaning, it runs the same code on both.

Example code:

    when click button #search then                  // Bind event to browser button
    GET dekra order by URI param ?search            // Backend API call
    if length > 1 then show order list              // Show result from API call as a list in browser using Ajax
    if length = 1 then populate order as receipt
    if length = 0 then show error "Found no order"

## Notes

    if length: > 1
